<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\TicketType;
use App\Enum\StockStatus;

final class TicketTypeResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public int $priceCents,
        public bool $disabled,
        public ?string $disabledReason,
        public int $sortOrder,
        public ?string $sharedStockId,
        public ?int $stockQuantity,
        public ?StockStatus $stockStatus,
        public ?int $lowStockThreshold,
        public bool $lowStock,
    ) {
    }

    public static function fromEntity(TicketType $ticketType): self
    {
        $sharedStock = $ticketType->sharedStock;

        return new self(
            id: (string) $ticketType->id,
            name: $ticketType->name,
            description: $ticketType->description,
            priceCents: (int) $ticketType->price->getAmount(),
            disabled: $ticketType->disabled,
            disabledReason: $ticketType->disabledReason,
            sortOrder: $ticketType->sortOrder,
            sharedStockId: $sharedStock !== null ? (string) $sharedStock->id : null,
            stockQuantity: $sharedStock?->stockQuantity,
            stockStatus: $sharedStock?->stockStatus,
            lowStockThreshold: $sharedStock?->lowStockThreshold,
            lowStock: $sharedStock?->isLowStock() ?? false,
        );
    }
}
