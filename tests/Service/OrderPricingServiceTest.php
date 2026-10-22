<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coupon;
use App\Entity\Event;
use App\Entity\TicketType;
use App\Enum\DiscountType;
use App\Service\DiscountCalculator;
use App\Service\MoneyService;
use App\Service\OrderPricingService;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class OrderPricingServiceTest extends TestCase
{
    private OrderPricingService $service;

    protected function setUp(): void
    {
        $moneyService = new MoneyService();
        $this->service = new OrderPricingService($moneyService, new DiscountCalculator($moneyService));
    }

    private function ticketType(int $cents): TicketType
    {
        return new TicketType(
            new Event('Test Show', new \DateTimeImmutable('2026-09-01 19:30')),
            'Regulier',
            Money::EUR($cents),
        );
    }

    public function testNoCouponTotalsReconcile(): void
    {
        $cart = [
            ['ticketType' => $this->ticketType(1200), 'quantity' => 6],
            ['ticketType' => $this->ticketType(1000), 'quantity' => 1],
        ];

        $totals = $this->service->calculate($cart, null, 9.0);

        $this->assertSame('8200', $totals->subtotalGross->getAmount());
        $this->assertSame('8200', $totals->totalGross->getAmount());
        $this->assertTrue($totals->discountGross->isZero());
        $this->assertTrue($totals->subtotalNet->add($totals->tax)->equals($totals->totalGross));
    }

    public function testPercentCouponApplied(): void
    {
        $cart = [['ticketType' => $this->ticketType(10000), 'quantity' => 1]];
        $coupon = new Coupon('TEN', DiscountType::PERCENT, 1000); // 10%

        $totals = $this->service->calculate($cart, $coupon, 9.0);

        $this->assertSame('10000', $totals->subtotalGross->getAmount());
        $this->assertSame('1000', $totals->discountGross->getAmount());
        $this->assertSame('9000', $totals->totalGross->getAmount());
        $this->assertTrue($totals->subtotalNet->add($totals->tax)->equals($totals->totalGross));
    }

    public function testFixedCouponApplied(): void
    {
        $cart = [['ticketType' => $this->ticketType(5000), 'quantity' => 2]];
        $coupon = new Coupon('FIVE', DiscountType::FIXED_CART, 500); // €5.00 off

        $totals = $this->service->calculate($cart, $coupon, 9.0);

        $this->assertSame('10000', $totals->subtotalGross->getAmount());
        $this->assertSame('500', $totals->discountGross->getAmount());
        $this->assertSame('9500', $totals->totalGross->getAmount());
        $this->assertTrue($totals->subtotalNet->add($totals->tax)->equals($totals->totalGross));
    }

    public function testByTicketCouponMakesHighestPricedTicketUnitsFree(): void
    {
        $cart = [
            ['ticketType' => $this->ticketType(1200), 'quantity' => 2],
            ['ticketType' => $this->ticketType(800), 'quantity' => 3],
            ['ticketType' => $this->ticketType(1000), 'quantity' => 1],
        ];
        $coupon = new Coupon('TWO-FREE', DiscountType::BY_TICKET, 3);

        $totals = $this->service->calculate($cart, $coupon, 9.0);

        $this->assertSame('5800', $totals->subtotalGross->getAmount());
        $this->assertSame('3400', $totals->discountGross->getAmount());
        $this->assertSame('2400', $totals->totalGross->getAmount());
    }

    public function testOneByTicketCouponMakesOneTenEuroTicketFree(): void
    {
        $coupon = new Coupon('ONE-FREE', DiscountType::BY_TICKET, 1);
        $totals = $this->service->calculate([
            ['ticketType' => $this->ticketType(1000), 'quantity' => 1],
        ], $coupon, 9.0);

        $this->assertSame('1000', $totals->discountGross->getAmount());
        $this->assertTrue($totals->totalGross->isZero());
    }

    public function testByTicketCouponCapsDiscountAtCartQuantityAndPreviewUsesCurrentPrices(): void
    {
        $coupon = new Coupon('ALL-FREE', DiscountType::BY_TICKET, 5);
        $cart = [
            ['ticketType' => $this->ticketType(1200), 'quantity' => 1],
            ['ticketType' => $this->ticketType(800), 'quantity' => 1],
        ];

        $initial = $this->service->calculate($cart, $coupon, 9.0);
        $preview = $this->service->calculateFromTicketRows([
            ['price' => Money::EUR(1500), 'quantity' => 1],
            ['price' => Money::EUR(800), 'quantity' => 1],
        ], $coupon, 9.0);

        $this->assertSame('2000', $initial->discountGross->getAmount());
        $this->assertSame('2300', $preview->subtotalGross->getAmount());
        $this->assertSame('2300', $preview->discountGross->getAmount());
        $this->assertTrue($preview->totalGross->isZero());
    }

    public function testFullDiscountProducesZeroTotal(): void
    {
        $cart = [['ticketType' => $this->ticketType(5000), 'quantity' => 1]];
        $coupon = new Coupon('FREE', DiscountType::FIXED_CART, 5000);

        $totals = $this->service->calculate($cart, $coupon, 9.0);

        $this->assertTrue($totals->totalGross->isZero());
        $this->assertSame('5000', $totals->discountGross->getAmount());
    }

    public function testDiscountNetAndTaxReconcile(): void
    {
        $cart = [['ticketType' => $this->ticketType(8200), 'quantity' => 1]];
        $coupon = new Coupon('TEN', DiscountType::PERCENT, 1000); // 10%

        $totals = $this->service->calculate($cart, $coupon, 9.0);

        $this->assertTrue(
            $totals->discountNet->add($totals->discountTax)->equals($totals->discountGross),
            'discountNet + discountTax must equal discountGross',
        );
    }

    public function testEmptyCartProducesZeros(): void
    {
        $totals = $this->service->calculate([], null, 9.0);

        $this->assertTrue($totals->subtotalGross->isZero());
        $this->assertTrue($totals->totalGross->isZero());
        $this->assertTrue($totals->discountGross->isZero());
        $this->assertTrue($totals->tax->isZero());
    }
}
