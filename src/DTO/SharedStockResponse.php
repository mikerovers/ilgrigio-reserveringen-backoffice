<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\SharedStock;
use App\Enum\StockStatus;

final class SharedStockResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public ?int $stockQuantity,
        public StockStatus $stockStatus,
        public ?int $lowStockThreshold,
        public bool $lowStock,
    ) {
    }

    public static function fromEntity(SharedStock $sharedStock): self
    {
        return new self(
            id: (string) $sharedStock->id,
            name: $sharedStock->name,
            stockQuantity: $sharedStock->stockQuantity,
            stockStatus: $sharedStock->stockStatus,
            lowStockThreshold: $sharedStock->lowStockThreshold,
            lowStock: $sharedStock->isLowStock(),
        );
    }
}
