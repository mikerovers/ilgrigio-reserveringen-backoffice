<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ticket;

/**
 * Outcome of checking in one ticket as part of an order check-in.
 */
final readonly class TicketCheckInOutcome
{
    public function __construct(
        public Ticket $ticket,
        public CheckInResult $result,
    ) {
    }
}
