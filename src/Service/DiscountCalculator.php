<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Discount;
use App\Entity\Coupon;
use App\Enum\DiscountType;
use Money\Money;

/**
 * Discounts apply to the tax-inclusive (gross) cart total. PERCENT coupons
 * store basis points (1000 = 10%), FIXED_CART coupons store cents. The result
 * never exceeds the cart total.
 */
final class DiscountCalculator
{
    public function __construct(private MoneyService $moneyService)
    {
    }

    public function isApplicable(Coupon $coupon, \DateTimeImmutable $now): bool
    {
        if (!$coupon->active) {
            return false;
        }

        return $coupon->expiresAt === null || $coupon->expiresAt > $now;
    }

    /**
     * The gross discount, capped at the cart total.
     */
    public function discountGross(Coupon|Discount $source, Money $cartGross): Money
    {
        $rule = $source instanceof Coupon ? $source->toDiscount() : $source;

        $discount = match ($rule->type) {
            DiscountType::PERCENT => $cartGross->multiply($rule->amount)->divide(10000),
            DiscountType::FIXED_CART => Money::EUR($rule->amount),
        };

        if ($discount->isNegative()) {
            return Money::EUR(0);
        }

        return $discount->greaterThan($cartGross) ? $cartGross : $discount;
    }

    /**
     * Split a gross discount into [net, tax] for the given VAT rate, so the
     * order's discount_tax stays consistent with the rest of the totals.
     *
     * @return array{0: Money, 1: Money} [net, tax]
     */
    public function splitDiscount(Money $discountGross, float $taxRatePercent): array
    {
        return $this->moneyService->splitGross($discountGross, $taxRatePercent);
    }
}
