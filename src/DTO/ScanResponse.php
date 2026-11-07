<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Ticket;
use App\Service\CheckInResult;
use App\Service\VenueTime;
use OpenApi\Attributes as OA;

/**
 * Outcome of one scan at the door scanner app, shown in the result panel.
 */
final class ScanResponse
{
    public function __construct(
        public CheckInResult $result,
        public string $ticketCode,
        public ?string $ticketTypeName,
        public ?string $orderNumber,
        /** Moment the ticket was (first) checked in, venue time HH:MM. */
        public ?string $checkedInTime,
        public ?string $checkedInBy,
        #[OA\Property(format: 'date-time')]
        public ?string $checkedInAt,
        public EventStatsResponse $stats,
    ) {
    }

    public static function create(
        CheckInResult $result,
        string $ticketCode,
        ?Ticket $ticket,
        EventStatsResponse $stats,
    ): self {
        $checkedInAt = $ticket?->checkedInAt;

        return new self(
            result: $result,
            ticketCode: $ticketCode,
            ticketTypeName: $ticket?->ticketType->name,
            orderNumber: $ticket?->order->orderNumber,
            checkedInTime: $checkedInAt !== null ? VenueTime::toLocal($checkedInAt)->format('H:i') : null,
            checkedInBy: $ticket?->checkedInBy,
            checkedInAt: $checkedInAt?->format(\DateTimeInterface::ATOM),
            stats: $stats,
        );
    }
}
