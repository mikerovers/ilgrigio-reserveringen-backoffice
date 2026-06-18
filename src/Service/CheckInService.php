<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Door check-in for tickets, replacing Tickera's attendance handling.
 */
final class CheckInService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Admit a ticket at the door, scoped to an event. Returns a result describing
     * the outcome; never throws on a bad/already-used code.
     */
    public function checkIn(
        Event $event,
        string $ticketCode,
        \DateTimeImmutable $now,
        ?string $by = null,
    ): CheckInResult {
        $ticket = $this->ticketRepository->findOneForCheckIn(
            $event,
            $ticketCode,
        );

        if ($ticket === null) {
            return CheckInResult::NOT_FOUND;
        }

        if ($ticket->status === TicketStatus::VOID) {
            return CheckInResult::VOID;
        }

        if ($ticket->status === TicketStatus::CHECKED_IN) {
            return CheckInResult::ALREADY_CHECKED_IN;
        }

        $ticket->status = TicketStatus::CHECKED_IN;
        $ticket->checkedInAt = $now;
        $ticket->checkedInBy = $by;
        $this->entityManager->flush();

        return CheckInResult::ADMITTED;
    }

    /**
     * Look up a ticket for an event by its code (e.g. to render a check-in result).
     */
    public function findTicket(Event $event, string $ticketCode): ?Ticket
    {
        return $this->ticketRepository->findOneForCheckIn($event, $ticketCode);
    }

    /**
     * Invalidate every ticket of an order (e.g. on cancellation or refund).
     */
    public function voidOrderTickets(Order $order): void
    {
        foreach ($order->tickets as $ticket) {
            if ($ticket->status !== TicketStatus::CHECKED_IN) {
                $ticket->status = TicketStatus::VOID;
            }
        }

        $this->entityManager->flush();
    }
}
