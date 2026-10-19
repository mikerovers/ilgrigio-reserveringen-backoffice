<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use App\Entity\Event;

final class EventListResponse
{
    /**
     * @param EventCategoryResponse[] $categories
     * @param TicketTypeResponse[]    $ticketTypes
     * @param SharedStockResponse[]   $sharedStocks
     */
    public function __construct(
        public string $id,
        public string $name,
        #[OA\Property(description: 'Event start. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public string $startsAt,
        #[OA\Property(description: 'Event end. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $endsAt,
        #[OA\Property(description: 'Ticket sales open. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $soldFrom,
        #[OA\Property(description: 'Ticket sales close. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $soldUntil,
        public ?string $location,
        public ?string $description,
        public array $categories,
        public array $ticketTypes,
        public array $sharedStocks,
    ) {
    }

    public static function fromEntity(Event $event): self
    {
        return new self(
            id: (string) $event->id,
            name: $event->name,
            startsAt: $event->startsAt->format(\DateTimeInterface::ATOM),
            endsAt: $event->endsAt?->format(\DateTimeInterface::ATOM),
            soldFrom: $event->soldFrom?->format(\DateTimeInterface::ATOM),
            soldUntil: $event->soldUntil?->format(\DateTimeInterface::ATOM),
            location: $event->location,
            description: $event->description,
            categories: array_map(
                EventCategoryResponse::fromEntity(...),
                $event->categories->toArray(),
            ),
            ticketTypes: array_map(
                TicketTypeResponse::fromEntity(...),
                $event->ticketTypes->toArray(),
            ),
            sharedStocks: array_map(
                SharedStockResponse::fromEntity(...),
                $event->sharedStocks(),
            ),
        );
    }
}
