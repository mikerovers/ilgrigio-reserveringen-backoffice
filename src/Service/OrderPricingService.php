<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\OrderTotals;
use App\Entity\Coupon;
use App\Entity\TicketType;
use Money\Money;

/**
 * Pure, stateless service that computes all price components for a cart.
 *
 * Given an array of {ticketType, quantity} rows and an optional coupon,
 * returns an OrderTotals value object containing every amount needed to
 * display a checkout summary or persist an Order entity. No Doctrine or
 * side effects — fully unit-testable.
 */
final class OrderPricingService
{
    public function __construct(
        private MoneyService $moneyService,
        private DiscountCalculator $discountCalculator,
    ) {
    }

    /**
     * @param array<array{ticketType: TicketType, quantity: int}> $cart
     */
    public function calculate(array $cart, ?Coupon $coupon, float $taxRatePercent): OrderTotals
    {
        $subtotalGross = Money::EUR(0);

        foreach ($cart as $row) {
            $lineGross = $row['ticketType']->price->multiply($row['quantity']);
            $subtotalGross = $subtotalGross->add($lineGross);
        }

        return $this->calculateFromCartGross($subtotalGross, $coupon, $taxRatePercent);
    }

    /**
     * Preview calculation when only a pre-computed gross total is available
     * (e.g. cart items stored as floats in the session). Uses the same
     * discount and net/tax split logic as calculate().
     */
    public function calculateFromCartGross(Money $cartGross, ?Coupon $coupon, float $taxRatePercent): OrderTotals
    {
        $discountGross = Money::EUR(0);
        $discountNet = Money::EUR(0);
        $discountTax = Money::EUR(0);

        if ($coupon !== null) {
            $discountGross = $this->discountCalculator->discountGross($coupon, $cartGross);
            [$discountNet, $discountTax] = $this->discountCalculator->splitDiscount($discountGross, $taxRatePercent);
        }

        $totalGross = $cartGross->subtract($discountGross);
        [$subtotalNet, $tax] = $this->moneyService->splitGross($totalGross, $taxRatePercent);

        return new OrderTotals(
            subtotalGross: $cartGross,
            discountGross: $discountGross,
            discountNet: $discountNet,
            discountTax: $discountTax,
            totalGross: $totalGross,
            subtotalNet: $subtotalNet,
            tax: $tax,
        );
    }
}
