<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Discount;
use App\Entity\Coupon;
use App\Enum\DiscountType;
use Money\Money;

/**
 * Discounts apply to the tax-inclusive (gross) cart total. PERCENT coupons
 * store basis points (1000 = 10%), FIXED_CART coupons store cents, and
 * BY_TICKET coupons store a ticket count. The result never exceeds the cart total.
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
    /**
     * @param array<array{price: Money, quantity: int}>|null $ticketRows
     */
    public function discountGross(Coupon|Discount $source, Money $cartGross, ?array $ticketRows = null): Money
    {
        $rule = $source instanceof Coupon ? $source->toDiscount() : $source;

        $discount = match ($rule->type) {
            DiscountType::PERCENT => $cartGross->multiply($rule->amount)->divide(10000),
            DiscountType::FIXED_CART => Money::EUR($rule->amount),
            DiscountType::BY_TICKET => $this->discountHighestPricedTickets($rule->amount, $ticketRows),
        };

        if ($discount->isNegative()) {
            return Money::EUR(0);
        }

        return $discount->greaterThan($cartGross) ? $cartGross : $discount;
    }

    /**
     * @param array<array{price: Money, quantity: int}>|null $ticketRows
     */
    private function discountHighestPricedTickets(int $ticketCount, ?array $ticketRows): Money
    {
        if ($ticketRows === null) {
            throw new \LogicException('By-ticket discounts require ticket prices and quantities.');
        }

        usort($ticketRows, static fn (array $a, array $b): int => $b['price']->compare($a['price']));

        $remaining = $ticketCount;
        $discount = Money::EUR(0);
        foreach ($ticketRows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $discountedQuantity = min($remaining, $row['quantity']);
            $discount = $discount->add($row['price']->multiply($discountedQuantity));
            $remaining -= $discountedQuantity;
        }

        return $discount;
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
