<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\TicketType;
use App\Enum\DiscountType;
use App\Repository\CustomerRepository;
use App\Service\CheckoutService;
use App\Service\DiscountCalculator;
use App\Service\MoneyService;
use App\Service\OrderNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use PHPUnit\Framework\TestCase;

class CheckoutServiceTest extends TestCase
{
    private CheckoutService $checkoutService;

    protected function setUp(): void
    {
        $moneyService = new MoneyService();
        $this->checkoutService = new CheckoutService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(CustomerRepository::class),
            $moneyService,
            new DiscountCalculator($moneyService),
            new OrderNumberGenerator(),
            9.0,
        );
    }

    private function customer(): Customer
    {
        return new Customer('jan@example.com', 'Jan', 'Jansen');
    }

    private function ticketType(string $name, int $cents): TicketType
    {
        $event = new Event('Test Show', new \DateTimeImmutable('2026-09-01 19:30'));

        return new TicketType($event, $name, Money::EUR($cents));
    }

    public function testOrderTotalsReconcileWithoutDiscount(): void
    {
        $cart = [
            ['ticketType' => $this->ticketType('Regulier', 1200), 'quantity' => 6],
            ['ticketType' => $this->ticketType('Kind', 1000), 'quantity' => 1],
        ];

        $order = $this->checkoutService->createOrder($cart, $this->customer(), null, new \DateTimeImmutable());

        // 6 * 12.00 + 1 * 10.00 = 82.00 gross
        $this->assertSame('8200', $order->totalGross->getAmount());
        // net + tax must reconcile to gross
        $this->assertTrue(
            $order->subtotalNet->add($order->tax)->equals($order->totalGross),
            'net + tax must equal gross total',
        );
    }

    public function testPercentCouponIsApplied(): void
    {
        $cart = [['ticketType' => $this->ticketType('Regulier', 10000), 'quantity' => 1]];
        $coupon = new Coupon('TEN', DiscountType::PERCENT, 1000); // 10%

        $order = $this->checkoutService->createOrder($cart, $this->customer(), $coupon, new \DateTimeImmutable());

        $this->assertSame('1000', $order->discountGross->getAmount());
        $this->assertSame('9000', $order->totalGross->getAmount());
        $this->assertSame('TEN', $order->couponCode);
        $this->assertTrue($order->subtotalNet->add($order->tax)->equals($order->totalGross));
    }

    public function testFullDiscountProducesZeroTotal(): void
    {
        $cart = [['ticketType' => $this->ticketType('Regulier', 5000), 'quantity' => 1]];
        $coupon = new Coupon('FREE', DiscountType::FIXED_CART, 5000); // €50.00 off €50.00

        $order = $this->checkoutService->createOrder($cart, $this->customer(), $coupon, new \DateTimeImmutable());

        $this->assertTrue($order->totalGross->isZero(), 'fully-discounted order is zero-total');
    }
}
