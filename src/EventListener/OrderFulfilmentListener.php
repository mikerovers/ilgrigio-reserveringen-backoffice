<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\OrderCompletedEvent;
use App\Message\SendOrderEmailMessage;
use App\Service\SecurePdfStorageService;
use App\Service\TicketIssuanceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles fulfilment when an order is completed: issues tickets then queues the
 * confirmation email. Idempotent — ticket issuance is a no-op if tickets already exist.
 */
#[AsEventListener(event: OrderCompletedEvent::class)]
final class OrderFulfilmentListener
{
    public function __construct(
        private TicketIssuanceService $ticketIssuanceService,
        private SecurePdfStorageService $pdfStorageService,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OrderCompletedEvent $event): void
    {
        $order = $event->order;

        $this->ticketIssuanceService->issueForOrder($order, $this->clock->now());
        $this->entityManager->flush();

        $token = $this->pdfStorageService->generateSecureTokenForOrder($order->orderNumber);
        $this->messageBus->dispatch(new SendOrderEmailMessage((string) $order->id, $token));

        $this->logger->info('Order fulfilment dispatched', [
            'order_number' => $order->orderNumber,
        ]);
    }
}
