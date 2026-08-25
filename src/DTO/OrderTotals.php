<?php

declare(strict_types=1);

namespace App\DTO;

use Money\Money;

/**
 * Immutable snapshot of all price components for an order, computed by
 * OrderPricingService. All amounts are in EUR cents (Money value objects).
 *
 * Invariant: subtotalNet + tax === totalGross
 */
final readonly class OrderTotals
{
    public function __construct(
        /** Gross cart total before any discount. */
        public Money $subtotalGross,
        /** Gross discount applied (zero if no coupon). */
        public Money $discountGross,
        /** Net portion of the discount. */
        public Money $discountNet,
        /** Tax portion of the discount. */
        public Money $discountTax,
        /** Final gross total (subtotalGross − discountGross). */
        public Money $totalGross,
        /** Net component of totalGross (excl. VAT). */
        public Money $subtotalNet,
        /** VAT component of totalGross. */
        public Money $tax,
    ) {
    }
}
