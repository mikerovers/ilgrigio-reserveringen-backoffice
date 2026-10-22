<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Discount;
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
    public function calculate(array $cart, Coupon|Discount|null $coupon, float $taxRatePercent): OrderTotals
    {
        $subtotalGross = Money::EUR(0);
        $ticketRows = [];

        foreach ($cart as $row) {
            $lineGross = $row['ticketType']->price->multiply($row['quantity']);
            $subtotalGross = $subtotalGross->add($lineGross);
            $ticketRows[] = ['price' => $row['ticketType']->price, 'quantity' => $row['quantity']];
        }

        return $this->calculateTotals($subtotalGross, $coupon, $taxRatePercent, $ticketRows);
    }

    /**
     * @param array<array{price: Money, quantity: int}> $ticketRows
     */
    public function calculateFromTicketRows(
        array $ticketRows,
        Coupon|Discount|null $coupon,
        float $taxRatePercent,
    ): OrderTotals {
        $cartGross = Money::EUR(0);
        foreach ($ticketRows as $row) {
            $cartGross = $cartGross->add($row['price']->multiply($row['quantity']));
        }

        return $this->calculateTotals($cartGross, $coupon, $taxRatePercent, $ticketRows);
    }

    /**
     * Preview calculation when only a pre-computed gross total is available.
     * By-ticket coupons require calculateFromTicketRows() or calculate().
     */
    public function calculateFromCartGross(
        Money $cartGross,
        Coupon|Discount|null $coupon,
        float $taxRatePercent,
    ): OrderTotals {
        return $this->calculateTotals($cartGross, $coupon, $taxRatePercent);
    }

    /**
     * @param array<array{price: Money, quantity: int}>|null $ticketRows
     */
    private function calculateTotals(
        Money $cartGross,
        Coupon|Discount|null $coupon,
        float $taxRatePercent,
        ?array $ticketRows = null,
    ): OrderTotals {
        $discountGross = Money::EUR(0);
        $discountNet = Money::EUR(0);
        $discountTax = Money::EUR(0);

        if ($coupon !== null) {
            $discountGross = $this->discountCalculator->discountGross($coupon, $cartGross, $ticketRows);
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
