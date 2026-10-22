<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\DiscountType;

/**
 * A discount rule independent of where it comes from (a persisted Coupon or an
 * ad-hoc register discount). Amount is basis points for PERCENT, cents for
 * FIXED_CART, or a ticket count for BY_TICKET.
 */
final class Discount
{
    public function __construct(
        public readonly DiscountType $type,
        public readonly int $amount,
    ) {
    }
}
