<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderNote;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancels an order: returns its stock, voids its tickets and frees its coupon
 * redemption. Money is not moved here (cash refunds happen at the till), so no
 * refund is issued.
 */
final class OrderCancellationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockReservationService $stockReservationService,
        private CheckInService $checkInService,
        private TicketRepository $ticketRepository,
        private CheckInBroadcaster $broadcaster,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Idempotent: cancelling an already cancelled order is a no-op.
     *
     * @return bool true when this call cancelled the order, false when it already was
     *
     * @throws \DomainException when the order may not be cancelled (maps to 409)
     */
    public function cancel(Order $order, string $cancelledBy): bool
    {
        if ($order->status === OrderStatus::CANCELLED) {
            return false;
        }

        if (!in_array($order->status, [OrderStatus::PENDING, OrderStatus::PROCESSING, OrderStatus::COMPLETED], true)) {
            throw new \DomainException(sprintf('Order %s cannot be cancelled (status: %s).', $order->orderNumber, $order->status->value));
        }

        foreach ($order->tickets as $ticket) {
            if ($ticket->status === TicketStatus::CHECKED_IN) {
                throw new \DomainException('Order has tickets that were already checked in.');
            }
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $order->status = OrderStatus::CANCELLED;

            $this->stockReservationService->releaseForOrder($order);
            $this->checkInService->voidOrderTickets($order);

            $note = new OrderNote($order, sprintf('Bestelling geannuleerd door %s.', $cancelledBy));
            $order->notes->add($note);
            $this->entityManager->persist($note);
            $this->entityManager->flush();

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $this->broadcastVoidedCounters($order);

        $this->logger->info('Register order cancelled', [
            'order_number' => $order->orderNumber,
            'cancelled_by' => $cancelledBy,
        ]);

        return true;
    }

    /**
     * Voiding moves tickets out of "remaining" without any door scan, so the
     * balies of every show the order touched are told the new counters. Runs
     * after the commit so they never read counts that could still roll back.
     */
    private function broadcastVoidedCounters(Order $order): void
    {
        $events = [];
        foreach ($order->tickets as $ticket) {
            $events[$ticket->event->id->toRfc4122()] = $ticket->event;
        }

        $now = new \DateTimeImmutable();
        foreach ($events as $event) {
            $this->broadcaster->changed($event, $this->ticketRepository->countByStatusForEvent($event), $now);
        }
    }
}
