<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\OrderCompleted;
use App\Message\SendOrderEmailMessage;
use App\Repository\OrderRepository;
use App\Service\SecurePdfStorageService;
use App\Service\TicketIssuanceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fulfilment fan-out for a completed order: issue tickets, then queue the
 * confirmation email/PDF. Idempotent — ticket issuance no-ops if already done,
 * so a redelivered message is safe.
 */
#[AsMessageHandler]
final class OrderCompletedHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private TicketIssuanceService $ticketIssuanceService,
        private SecurePdfStorageService $pdfStorageService,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OrderCompleted $message): void
    {
        $order = $this->orderRepository->find($message->orderId);

        if ($order === null) {
            $this->logger->error('OrderCompleted for unknown order', [
                'order_id' => $message->orderId,
            ]);

            return;
        }

        $this->ticketIssuanceService->issueForOrder($order, $this->clock->now());
        $this->entityManager->flush();

        $token = $this->pdfStorageService->generateSecureTokenForOrder($order->orderNumber);
        $this->messageBus->dispatch(new SendOrderEmailMessage($message->orderId, $token));

        $this->logger->info('Order fulfilment dispatched', [
            'order_number' => $order->orderNumber,
        ]);
    }
}
