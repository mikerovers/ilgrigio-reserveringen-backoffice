<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Enum\PaymentStatus;
use App\Event\OrderCompletedEvent;
use App\Event\PaymentStatusChangedEvent;
use App\Repository\PaymentRepository;
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
        $previousStatus = $payment->status;

        $payment->status = $newStatus;

        if ($newStatus === PaymentStatus::PAID && $payment->paidAt === null) {
            $payment->paidAt = $this->clock->now();
        }

        $order->status = $newStatus->toOrderStatus();

        if ($newStatus === PaymentStatus::PAID) {
            $order->completeNow($this->clock->now());
        }

        // Dispatch payment status event; the history listener adds a note and flushes.
        $this->eventDispatcher->dispatch(new PaymentStatusChangedEvent($order, $previousStatus, $newStatus));

        if ($newStatus === PaymentStatus::PAID) {
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));
        } else {
            $this->entityManager->flush();
        }

        $this->logger->info('Mollie webhook processed', [
            'mollie_id'       => $mollieId,
            'order_number'    => $order->orderNumber,
            'previous_status' => $previousStatus->value,
            'new_status'      => $newStatus->value,
        ]);
    }
}
