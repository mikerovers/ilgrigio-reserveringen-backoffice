<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\OrderLineItem;

final class OrderLineItemResponse
{
    public function __construct(
        public string $ticketTypeId,
        public string $name,
        public int $quantity,
        public int $unitGrossCents,
        public int $lineGrossCents,
    ) {
    }

    public static function fromEntity(OrderLineItem $item): self
    {
        return new self(
            ticketTypeId: (string) $item->ticketType->id,
            name: $item->name,
            quantity: $item->quantity,
            unitGrossCents: (int) $item->unitGross->getAmount(),
            lineGrossCents: (int) $item->lineGross->getAmount(),
        );
    }
}
