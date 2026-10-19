<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Discount;
use App\Entity\Coupon;
use App\Enum\DiscountType;
use App\Service\DiscountCalculator;
use App\Service\MoneyService;
use Money\Money;
use PHPUnit\Framework\TestCase;

class DiscountCalculatorTest extends TestCase
{
    private DiscountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DiscountCalculator(new MoneyService());
    }

    public function testPercentDiscountUsesBasisPoints(): void
    {
        $coupon = new Coupon('TEN', DiscountType::PERCENT, 1000); // 10%

        $discount = $this->calculator->discountGross($coupon, Money::EUR(8200));

        $this->assertSame('820', $discount->getAmount());
    }

    public function testFixedCartDiscountUsesCents(): void
    {
        $coupon = new Coupon('FIVE', DiscountType::FIXED_CART, 500); // €5.00

        $discount = $this->calculator->discountGross($coupon, Money::EUR(8200));

        $this->assertSame('500', $discount->getAmount());
    }

    public function testManualDiscountUsesTheSameRulesAsCoupons(): void
    {
        $percent = $this->calculator->discountGross(new Discount(DiscountType::PERCENT, 1250), Money::EUR(8000));
        $fixed = $this->calculator->discountGross(new Discount(DiscountType::FIXED_CART, 700), Money::EUR(8000));

        $this->assertSame('1000', $percent->getAmount()); // 12.5% of €80.00
        $this->assertSame('700', $fixed->getAmount());
    }

    public function testDiscountNeverExceedsCartTotal(): void
    {
        $coupon = new Coupon('HUGE', DiscountType::FIXED_CART, 10000); // €100.00

        $discount = $this->calculator->discountGross($coupon, Money::EUR(8200));

        $this->assertSame('8200', $discount->getAmount());
    }

    public function testSplitDiscountReconcilesToTheCent(): void
    {
        $discountGross = Money::EUR(820);

        [$net, $tax] = $this->calculator->splitDiscount($discountGross, 9.0);

        $this->assertTrue($net->add($tax)->equals($discountGross));
    }

    public function testInactiveCouponIsNotApplicable(): void
    {
        $coupon = new Coupon('OFF', DiscountType::PERCENT, 1000);
        $coupon->active = false;

        $this->assertFalse($this->calculator->isApplicable($coupon, new \DateTimeImmutable()));
    }

    public function testExpiredCouponIsNotApplicable(): void
    {
        $coupon = new Coupon('OLD', DiscountType::PERCENT, 1000);
        $coupon->expiresAt = new \DateTimeImmutable('2020-01-01');

        $this->assertFalse($this->calculator->isApplicable($coupon, new \DateTimeImmutable()));
    }

    public function testNegativeAmountNeverIncreasesTheTotal(): void
    {
        $calculator = new DiscountCalculator(new MoneyService());

        foreach ([DiscountType::PERCENT, DiscountType::FIXED_CART] as $type) {
            $coupon = new Coupon('NEG', $type, -500);

            $this->assertTrue($calculator->discountGross($coupon, Money::EUR(5000))->isZero(), $type->value);
        }
    }
}
