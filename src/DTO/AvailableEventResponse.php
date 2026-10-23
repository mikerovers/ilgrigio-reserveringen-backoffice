<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use App\Entity\Event;
use App\Enum\StockStatus;

/**
 * Public view of an event for the available-events API, built from an Event
 * entity and serialized to JSON.
 */
final class AvailableEventResponse
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        #[OA\Property(description: 'Event start. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public string $startsAt,
        #[OA\Property(description: 'Doors open. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $doorsOpenAt,
        public ?string $location,
        public StockStatus $stockStatus,
        public ?int $stockQuantity,
        public ?int $lowestPriceCents,
    ) {
    }

    public static function fromEntity(Event $event): self
    {
        $lowest = null;
        foreach ($event->ticketTypes as $ticketType) {
            $cents = (int) $ticketType->price->getAmount();
            if ($lowest === null || $cents < $lowest) {
                $lowest = $cents;
            }
        }

        return new self(
            id: (string) $event->id,
            slug: (string) $event->slug,
            name: $event->name,
            startsAt: $event->startsAt->format(\DateTimeInterface::ATOM),
            doorsOpenAt: $event->doorsOpenAt?->format(\DateTimeInterface::ATOM),
            location: $event->location,
            stockStatus: $event->displayStockStatus(),
            stockQuantity: $event->displayStockQuantity(),
            lowestPriceCents: $lowest,
        );
    }
}
