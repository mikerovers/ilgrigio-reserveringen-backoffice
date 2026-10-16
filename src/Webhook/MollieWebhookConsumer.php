<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use App\Event\OrderCompletedEvent;
use App\Event\PaymentStatusChangedEvent;
use App\Repository\PaymentRepository;
use App\Service\StockReservationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Omnipay\Mollie\Gateway;
use Omnipay\Mollie\Message\Response\FetchTransactionResponse;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[AsRemoteEventConsumer('mollie')]
final class MollieWebhookConsumer implements ConsumerInterface
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private StockReservationService $stockReservationService,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $mollieApiKey,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        $mollieId = $event->getPayload()['id'] ?? null;

        if (!$mollieId) {
            $this->logger->warning('Mollie webhook consumer received event without payment id', [
                'event_name' => $event->getName(),
            ]);

            return;
        }

        $payment = $this->paymentRepository->findOneByMolliePaymentId($mollieId);

        if ($payment === null) {
            $this->logger->warning('Mollie webhook for unknown payment', ['mollie_id' => $mollieId]);

            return;
        }

        $gateway = new Gateway();
        $gateway->setApiKey($this->mollieApiKey);

        /** @var FetchTransactionResponse $response */
        $response = $gateway->fetchTransaction([
            'transactionReference' => $mollieId,
        ])->send();

        $newStatus = PaymentStatus::from($response->getStatus());
        $order = $payment->order;

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Serialise parallel deliveries for the same payment; re-read state under the lock.
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($order);

            $previousStatus = $payment->status;
            $alreadyPaid = $order->status === OrderStatus::COMPLETED;
            $orderWasFailed = $order->status === OrderStatus::FAILED;

            if ($alreadyPaid && $newStatus !== PaymentStatus::PAID) {
                $connection->commit();
                $this->logger->warning('Ignoring non-paid Mollie status for completed order', [
                    'mollie_id'    => $mollieId,
                    'order_number' => $order->orderNumber,
                    'new_status'   => $newStatus->value,
                ]);

                return;
            }

            $payment->status = $newStatus;

            if ($newStatus === PaymentStatus::PAID && $payment->paidAt === null) {
                $payment->paidAt = $this->clock->now();
            }

            $order->status = $newStatus->toOrderStatus();

            if ($newStatus === PaymentStatus::PAID) {
                $order->completeNow($this->clock->now());

                if ($previousStatus->isFailed() || $orderWasFailed) {
                    // Stock was handed back when the payment failed; take it again.
                    $this->stockReservationService->reacquireForOrder($order);
                }
            } elseif ($newStatus->isFailed()) {
                $this->stockReservationService->releaseForOrder($order);
            }

            // Dispatch payment status event; the history listener adds a note and flushes.
            $this->eventDispatcher->dispatch(new PaymentStatusChangedEvent($order, $previousStatus, $newStatus));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        // Fulfilment is idempotent, so a replayed PAID webhook may safely retry it
        // when the first delivery committed but crashed before fulfilling.
        if ($newStatus === PaymentStatus::PAID && ($previousStatus !== PaymentStatus::PAID || $order->tickets->isEmpty())) {
            $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));
        }

        $this->logger->info('Mollie webhook processed', [
            'mollie_id'       => $mollieId,
            'order_number'    => $order->orderNumber,
            'previous_status' => $previousStatus->value,
            'new_status'      => $newStatus->value,
        ]);
    }
}
