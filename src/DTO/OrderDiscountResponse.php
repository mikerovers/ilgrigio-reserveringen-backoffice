<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;
use App\Enum\RegisterDiscountType;
use OpenApi\Attributes as OA;

final class OrderDiscountResponse
{
    public function __construct(
        public RegisterDiscountType $type,
        #[OA\Property(description: 'Percentage (10 = 10%) for type "percent"; whole cents for type "fixed"', type: 'number', example: 10)]
        public int|float $amount,
        public ?string $reason,
        public int $discountGrossCents,
    ) {
    }

    public static function fromEntity(Order $order): ?self
    {
        if ($order->manualDiscountType === null) {
            return null;
        }

        $type = RegisterDiscountType::fromDiscountType($order->manualDiscountType);
        $stored = (int) $order->manualDiscountAmount;
        // Stored as basis points for percent discounts; the API speaks plain percent.
        $percent = $stored / 100;

        return new self(
            type: $type,
            amount: $type === RegisterDiscountType::PERCENT ? ($percent == (int) $percent ? (int) $percent : $percent) : $stored,
            reason: $order->discountReason,
            discountGrossCents: (int) $order->discountGross->getAmount(),
        );
    }
}
