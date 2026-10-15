<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Event;

final class EventListResponse
{
    /**
     * @param EventCategoryResponse[] $categories
     * @param SharedStockResponse[]   $sharedStocks
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $startsAt,
        public ?string $endsAt,
        public ?string $location,
        public ?string $description,
        public array $categories,
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
            location: $event->location,
            description: $event->description,
            categories: array_map(
                EventCategoryResponse::fromEntity(...),
                $event->categories->toArray(),
            ),
            sharedStocks: array_map(
                SharedStockResponse::fromEntity(...),
                $event->sharedStocks(),
            ),
        );
    }
}
