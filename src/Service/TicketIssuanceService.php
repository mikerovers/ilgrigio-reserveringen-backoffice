<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mints tickets when an order reaches the COMPLETED state, replacing the
 * external Tickera ticket API. One Ticket row is created per unit of each line
 * item, carrying event-name/datetime snapshots so a scanned or re-rendered
 * ticket is stable even if the event is later edited.
 */
final class TicketIssuanceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TicketCodeGenerator $ticketCodeGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Issue tickets for an order. Idempotent: if the order already has tickets,
     * nothing is minted again.
     *
     * @return Ticket[]
     */
    public function issueForOrder(Order $order, \DateTimeImmutable $issuedAt): array
    {
        if (!$order->tickets->isEmpty()) {
            return $order->tickets->toArray();
        }

        $issued = [];

        foreach ($order->lineItems as $lineItem) {
            $event = $lineItem->ticketType->event;

            for ($i = 0; $i < $lineItem->quantity; $i++) {
                $ticket = new Ticket(
                    $this->ticketCodeGenerator->generate(),
                    $order,
                    $lineItem,
                    $lineItem->ticketType,
                    $event,
                    $lineItem->name,
                    $event->name,
                    $event->startsAt,
                    $issuedAt,
                    $i,
                );

                $order->tickets->add($ticket);
                $this->entityManager->persist($ticket);
                $issued[] = $ticket;
            }
        }

        $this->logger->info('Issued tickets for order', [
            'order_number' => $order->orderNumber,
            'count' => count($issued),
        ]);

        return $issued;
    }
}
