<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Ticket;
use App\Enum\TicketStatus;
use OpenApi\Attributes as OA;

final class TicketResponse
{
    public function __construct(
        public string $id,
        public string $ticketCode,
        public string $ticketName,
        public TicketStatus $status,
        public string $ticketTypeId,
        public string $eventName,
        #[OA\Property(description: 'Event start at issuance. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public string $eventStartsAt,
        #[OA\Property(description: 'Doors open at issuance. ISO 8601 with explicit UTC offset, or null when not announced.', format: 'date-time', example: '2026-10-19T19:30:00+00:00')]
        public ?string $eventDoorsOpenAt,
        public int $lineIndex,
        #[OA\Property(description: 'When the ticket was scanned. ISO 8601 with explicit UTC offset, or null when not checked in.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $checkedInAt,
        public ?string $checkedInBy,
        #[OA\Property(description: 'When the ticket was issued. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public string $issuedAt,
    ) {
    }

    public static function fromEntity(Ticket $ticket): self
    {
        return new self(
            id: (string) $ticket->id,
            ticketCode: $ticket->ticketCode,
            ticketName: $ticket->ticketName,
            status: $ticket->status,
            ticketTypeId: (string) $ticket->ticketType->id,
            eventName: $ticket->eventNameSnapshot,
            eventStartsAt: $ticket->eventDateTimeSnapshot->format(\DateTimeInterface::ATOM),
            eventDoorsOpenAt: $ticket->eventDoorsOpenSnapshot?->format(\DateTimeInterface::ATOM),
            lineIndex: $ticket->lineIndex,
            checkedInAt: $ticket->checkedInAt?->format(\DateTimeInterface::ATOM),
            checkedInBy: $ticket->checkedInBy,
            issuedAt: $ticket->issuedAt->format(\DateTimeInterface::ATOM),
        );
    }
}
