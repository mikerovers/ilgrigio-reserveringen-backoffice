<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;
use App\Enum\RegisterDiscountType;

final class OrderDiscountResponse
{
    public function __construct(
        public RegisterDiscountType $type,
        public int $amount,
        public ?string $reason,
        public int $discountGrossCents,
    ) {
    }

    public static function fromEntity(Order $order): ?self
    {
        if ($order->manualDiscountType === null) {
            return null;
        }

        return new self(
            type: RegisterDiscountType::fromDiscountType($order->manualDiscountType),
            amount: (int) $order->manualDiscountAmount,
            reason: $order->discountReason,
            discountGrossCents: (int) $order->discountGross->getAmount(),
        );
    }
}
