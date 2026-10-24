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
 *
 * Admission is decided by a single guarded UPDATE in TicketRepository, so
 * concurrent scanners cannot admit the same ticket twice.
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
        if ($this->ticketRepository->admitIfIssued($event, $ticketCode, $now, $by)) {
            return CheckInResult::ADMITTED;
        }

        // Not admitted: classify the ticket for the caller. The UPDATE above
        // already decided the outcome; this read only explains it.
        $ticket = $this->findTicket($event, $ticketCode);

        if ($ticket === null) {
            return CheckInResult::NOT_FOUND;
        }

        return match ($ticket->status) {
            TicketStatus::VOID => CheckInResult::VOID,
            TicketStatus::CHECKED_IN => CheckInResult::ALREADY_CHECKED_IN,
            TicketStatus::ISSUED => throw new \LogicException(sprintf(
                'Ticket %s is ISSUED but was not admitted.',
                $ticketCode,
            )),
        };
    }

    /**
     * Admit every issued ticket of an order in one atomic statement. Returns one
     * outcome per ticket of the order, in line-item order.
     *
     * @return TicketCheckInOutcome[]
     */
    public function checkInOrder(Order $order, \DateTimeImmutable $now, ?string $by = null): array
    {
        $admittedIds = array_flip($this->ticketRepository->admitIssuedForOrder($order, $now, $by));

        $outcomes = [];
        foreach ($this->ticketRepository->findByOrder($order) as $ticket) {
            // Reload: the statement above bypassed the unit of work.
            $this->entityManager->refresh($ticket);

            $result = match (true) {
                isset($admittedIds[(string) $ticket->id]) => CheckInResult::ADMITTED,
                $ticket->status === TicketStatus::VOID => CheckInResult::VOID,
                $ticket->status === TicketStatus::CHECKED_IN => CheckInResult::ALREADY_CHECKED_IN,
                default => throw new \LogicException(sprintf(
                    'Ticket %s is ISSUED but was not admitted.',
                    $ticket->ticketCode,
                )),
            };

            $outcomes[] = new TicketCheckInOutcome($ticket, $result);
        }

        return $outcomes;
    }

    /**
     * Look up a ticket for an event by its code (e.g. to render a check-in result).
     * Refreshes the entity so values written by a bulk UPDATE are not stale.
     */
    public function findTicket(Event $event, string $ticketCode): ?Ticket
    {
        $ticket = $this->ticketRepository->findOneForCheckIn($event, $ticketCode);

        if ($ticket !== null) {
            $this->entityManager->refresh($ticket);
        }

        return $ticket;
    }

    /**
     * Invalidate every issued ticket of an order (e.g. on cancellation or refund).
     *
     * The void is a guarded UPDATE, so a ticket scanned at the door cannot be
     * overwritten. If one was checked in before the void, the cancellation is
     * refused so the caller's transaction rolls back.
     *
     * @throws \DomainException when a ticket of the order is already checked in
     */
    public function voidOrderTickets(Order $order): void
    {
        $this->ticketRepository->voidIssuedForOrder($order);

        if ($this->ticketRepository->countCheckedInForOrder($order) > 0) {
            throw new \DomainException('Order has tickets that were already checked in.');
        }

        foreach ($order->tickets as $ticket) {
            $this->entityManager->refresh($ticket);
        }
    }
}
