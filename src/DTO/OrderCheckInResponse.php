<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;
use App\Service\CheckInResult;
use App\Service\TicketCheckInOutcome;

/**
 * Result of checking in all tickets of an order, serialized to JSON by $this->json().
 */
final class OrderCheckInResponse
{
    /**
     * @param CheckInResponse[] $tickets
     */
    public function __construct(
        public string $orderNumber,
        public int $admitted,
        public int $alreadyCheckedIn,
        public int $void,
        public array $tickets,
    ) {
    }

    /**
     * @param TicketCheckInOutcome[] $outcomes
     */
    public static function fromOutcomes(Order $order, array $outcomes): self
    {
        $counts = [
            CheckInResult::ADMITTED->name => 0,
            CheckInResult::ALREADY_CHECKED_IN->name => 0,
            CheckInResult::VOID->name => 0,
        ];

        $tickets = [];
        foreach ($outcomes as $outcome) {
            ++$counts[$outcome->result->name];

            $tickets[] = new CheckInResponse(
                result: $outcome->result,
                ticketCode: $outcome->ticket->ticketCode,
                ticketName: $outcome->ticket->ticketName,
                checkedInAt: $outcome->ticket->checkedInAt?->format(\DateTimeInterface::ATOM),
            );
        }

        return new self(
            orderNumber: $order->orderNumber,
            admitted: $counts[CheckInResult::ADMITTED->name],
            alreadyCheckedIn: $counts[CheckInResult::ALREADY_CHECKED_IN->name],
            void: $counts[CheckInResult::VOID->name],
            tickets: $tickets,
        );
    }
}
