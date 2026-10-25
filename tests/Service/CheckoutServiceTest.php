<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\SharedStock;
use App\Entity\TicketType;
use App\Enum\DiscountType;
use App\Exception\CouponUnavailableException;
use App\Repository\CouponRepository;
use App\Repository\CustomerRepository;
use App\Repository\SharedStockRepository;
use App\Service\CheckoutService;
use App\Service\DiscountCalculator;
use App\Service\MoneyService;
use App\Service\OrderNumberGenerator;
use App\Service\OrderPricingService;
use App\Entity\StockReservation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CheckoutServiceTest extends TestCase
{
    private CheckoutService $checkoutService;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&Connection $connection;
    private MockObject&SharedStockRepository $sharedStockRepository;
    private MockObject&CouponRepository $couponRepository;

    protected function setUp(): void
    {
        $moneyService = new MoneyService();
        $this->connection = $this->createMock(Connection::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->sharedStockRepository = $this->createMock(SharedStockRepository::class);
        $this->sharedStockRepository->method('reserve')->willReturn(true);
        $this->couponRepository = $this->createMock(CouponRepository::class);
        $this->checkoutService = new CheckoutService(
            $this->entityManager,
            $this->createMock(CustomerRepository::class),
            $this->sharedStockRepository,
            $this->couponRepository,
            $moneyService,
            new OrderPricingService($moneyService, new DiscountCalculator($moneyService)),
            new OrderNumberGenerator(),
            $this->createMock(EventDispatcherInterface::class),
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
        $ticketType = new TicketType($event, $name, Money::EUR($cents));
        $sharedStock = new SharedStock('Pool');
        $ticketType->sharedStock = $sharedStock;
        $event->ticketTypes->add($ticketType);

        return $ticketType;
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

    public function testStockIsReservedAtomicallyOncePerPoolAcrossTicketTypes(): void
    {
        $regular = $this->ticketType('Regulier', 1200);
        $child = $this->ticketType('Kind', 1000);
        $child->sharedStock = $regular->sharedStock;

        $reserved = [];
        $sharedStockRepository = $this->createMock(SharedStockRepository::class);
        $sharedStockRepository->method('reserve')->willReturnCallback(
            function (SharedStock $pool, int $qty) use (&$reserved): bool {
                $reserved[] = $qty;

                return true;
            },
        );
        $service = $this->serviceWith($sharedStockRepository);

        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );
        $this->connection->expects($this->once())->method('commit');

        $service->createOrder(
            [['ticketType' => $regular, 'quantity' => 2], ['ticketType' => $child, 'quantity' => 3]],
            $this->customer(),
            null,
            new \DateTimeImmutable(),
        );

        $this->assertSame([5], $reserved, 'cross-type demand is reserved as a single pool claim');
        $reservations = array_values(array_filter($persisted, fn ($e) => $e instanceof StockReservation));
        $this->assertCount(1, $reservations);
        $this->assertSame(5, $reservations[0]->quantity);
    }

    public function testInsufficientStockRollsBackAndPersistsNothing(): void
    {
        $sharedStockRepository = $this->createMock(SharedStockRepository::class);
        $sharedStockRepository->method('reserve')->willReturn(false);
        $service = $this->serviceWith($sharedStockRepository);

        $this->entityManager->expects($this->never())->method('persist');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ticket(s) beschikbaar');

        $ticketType = $this->ticketType('Regulier', 1200);
        $ticketType->sharedStock->stockQuantity = 1;

        $service->createOrder(
            [['ticketType' => $ticketType, 'quantity' => 2]],
            $this->customer(),
            null,
            new \DateTimeImmutable(),
        );
    }

    public function testOutOfStockPoolIsRejectedBeforeAnyReservation(): void
    {
        $sharedStockRepository = $this->createMock(SharedStockRepository::class);
        $sharedStockRepository->expects($this->never())->method('reserve');
        $service = $this->serviceWith($sharedStockRepository);

        $ticketType = $this->ticketType('Regulier', 1200);
        $ticketType->sharedStock->stockStatus = \App\Enum\StockStatus::OUT_OF_STOCK;

        $this->expectException(\RuntimeException::class);
        $service->createOrder(
            [['ticketType' => $ticketType, 'quantity' => 1]],
            $this->customer(),
            null,
            new \DateTimeImmutable(),
        );
    }

    private function serviceWith(SharedStockRepository $sharedStockRepository): CheckoutService
    {
        $moneyService = new MoneyService();

        return new CheckoutService(
            $this->entityManager,
            $this->createMock(CustomerRepository::class),
            $sharedStockRepository,
            $this->couponRepository,
            $moneyService,
            new OrderPricingService($moneyService, new DiscountCalculator($moneyService)),
            new OrderNumberGenerator(),
            $this->createMock(EventDispatcherInterface::class),
            9.0,
        );
    }

    public function testExhaustedCouponIsRejectedUnderLockAndRollsBack(): void
    {
        $coupon = new Coupon('ONCE', DiscountType::PERCENT, 1000);
        $coupon->usageLimit = 1;
        $this->couponRepository->expects($this->once())->method('lockForRedemption')->with($coupon);
        $this->couponRepository->method('isExhausted')->with($coupon)->willReturn(true);
        $this->connection->expects($this->once())->method('rollBack');
        $this->sharedStockRepository->expects($this->never())->method('reserve');

        $this->expectException(CouponUnavailableException::class);

        $cart = [['ticketType' => $this->ticketType('Regulier', 1200), 'quantity' => 1]];
        $this->checkoutService->createOrder($cart, $this->customer(), $coupon, new \DateTimeImmutable());
    }
}
