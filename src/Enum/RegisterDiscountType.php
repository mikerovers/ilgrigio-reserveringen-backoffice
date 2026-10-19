<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Discount kinds the register API accepts, mapped onto the shared DiscountType.
 */
enum RegisterDiscountType: string
{
    /** Amount is in basis points (1000 = 10%). */
    case PERCENT = 'percent';

    /** Amount is in cents off the cart total. */
    case FIXED = 'fixed';

    public static function fromDiscountType(DiscountType $type): self
    {
        return match ($type) {
            DiscountType::PERCENT => self::PERCENT,
            DiscountType::FIXED_CART => self::FIXED,
        };
    }

    public function toDiscountType(): DiscountType
    {
        return match ($this) {
            self::PERCENT => DiscountType::PERCENT,
            self::FIXED => DiscountType::FIXED_CART,
        };
    }
}
