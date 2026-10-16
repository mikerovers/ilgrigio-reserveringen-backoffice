<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\SharedStock;
use App\Entity\StockReservation;
use App\Enum\StockStatus;
use App\Repository\SharedStockRepository;
use App\Service\StockReservationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the atomic stock SQL against a real database; mocks cannot prove
 * the single-statement check-and-decrement.
 */
final class StockReservationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SharedStockRepository $pools;
    private StockReservationService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->pools = $container->get(SharedStockRepository::class);
        $this->service = $container->get(StockReservationService::class);
    }

    private function pool(?int $quantity): SharedStock
    {
        $pool = new SharedStock('Test pool ' . bin2hex(random_bytes(4)));
        $pool->stockQuantity = $quantity;
        $this->em->persist($pool);
        $this->em->flush();

        return $pool;
    }

    private function fresh(SharedStock $pool): SharedStock
    {
        $this->em->refresh($pool);

        return $pool;
    }

    public function testReserveNeverOversellsAndFlipsStatusAtZero(): void
    {
        $pool = $this->pool(3);

        $this->assertTrue($this->pools->reserve($pool, 2));
        $this->assertFalse($this->pools->reserve($pool, 2), 'only 1 left, 2 must be refused');
        $this->assertSame(1, $this->fresh($pool)->stockQuantity);

        $this->assertTrue($this->pools->reserve($pool, 1));
        $this->fresh($pool);
        $this->assertSame(0, $pool->stockQuantity);
        $this->assertSame(StockStatus::OUT_OF_STOCK, $pool->stockStatus);
        $this->assertFalse($this->pools->reserve($pool, 1));
    }

    public function testUnlimitedPoolIsNeverTouched(): void
    {
        $pool = $this->pool(null);

        $this->assertTrue($this->pools->reserve($pool, 1000));
        $this->assertNull($this->fresh($pool)->stockQuantity);
    }

    public function testReleaseReturnsStockExactlyOnce(): void
    {
        $pool = $this->pool(2);
        $customer = new Customer('stock-' . bin2hex(random_bytes(4)) . '@example.com', 'A', 'B');
        $order = new Order('TEST-' . bin2hex(random_bytes(4)), $customer, 900);
        $this->em->persist($customer);
        $this->em->persist($order);

        $this->assertTrue($this->pools->reserve($pool, 2));
        $this->em->persist(new StockReservation($order, $pool, 2, new \DateTimeImmutable()));
        $this->em->flush();
        $this->assertSame(StockStatus::OUT_OF_STOCK, $this->fresh($pool)->stockStatus);

        $this->assertSame(1, $this->service->releaseForOrder($order));
        $this->assertSame(0, $this->service->releaseForOrder($order), 'second release is a no-op');

        $this->fresh($pool);
        $this->assertSame(2, $pool->stockQuantity);
        $this->assertSame(StockStatus::IN_STOCK, $pool->stockStatus);

        // A late payment re-takes the stock; a second attempt changes nothing.
        $this->assertTrue($this->service->reacquireForOrder($order));
        $this->assertTrue($this->service->reacquireForOrder($order));
        $this->assertSame(0, $this->fresh($pool)->stockQuantity);
    }

    public function testReacquireReportsOversoldWhenPoolIsGone(): void
    {
        $pool = $this->pool(1);
        $customer = new Customer('stock-' . bin2hex(random_bytes(4)) . '@example.com', 'A', 'B');
        $order = new Order('TEST-' . bin2hex(random_bytes(4)), $customer, 900);
        $this->em->persist($customer);
        $this->em->persist($order);

        $this->assertTrue($this->pools->reserve($pool, 1));
        $this->em->persist(new StockReservation($order, $pool, 1, new \DateTimeImmutable()));
        $this->em->flush();

        $this->service->releaseForOrder($order);
        $this->assertTrue($this->pools->reserve($pool, 1), 'someone else buys the freed ticket');

        $this->assertFalse($this->service->reacquireForOrder($order));
    }
}
