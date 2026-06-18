<?php

declare(strict_types=1);

namespace App\Enum;

enum DiscountType: string
{
    /** Coupon amount is expressed in basis points (e.g. 1000 = 10%). */
    case PERCENT = 'percent';

    /** Coupon amount is expressed in cents off the cart total. */
    case FIXED_CART = 'fixed_cart';
}
