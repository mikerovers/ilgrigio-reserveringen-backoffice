<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Enum\DiscountType;
use Money\Money;

/**
 * Local replacement for WooCommerceCouponService discount math.
 *
 * Discounts apply to the tax-inclusive (gross) cart total, mirroring the
 * previous WooCommerce behaviour. PERCENT coupons store basis points (1000 =
 * 10%), FIXED_CART coupons store cents. The result never exceeds the cart total.
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
    public function discountGross(Coupon $coupon, Money $cartGross): Money
    {
        $discount = match ($coupon->discountType) {
            DiscountType::PERCENT => $cartGross->multiply($coupon->amount)->divide(10000),
            DiscountType::FIXED_CART => Money::EUR($coupon->amount),
        };

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
