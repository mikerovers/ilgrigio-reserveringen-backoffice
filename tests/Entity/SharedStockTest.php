<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\DTO\SharedStockResponse;
use App\Entity\SharedStock;
use PHPUnit\Framework\TestCase;

class SharedStockTest extends TestCase
{
    public function testIsLowStockWhenQuantityIsAtOrBelowThreshold(): void
    {
        $pool = new SharedStock('Pool');
        $pool->stockQuantity = 10;
        $pool->lowStockThreshold = 10;
        self::assertTrue($pool->isLowStock());

        $pool->stockQuantity = 11;
        self::assertFalse($pool->isLowStock());
    }

    public function testNeverLowWithoutThresholdOrUnlimitedStock(): void
    {
        $pool = new SharedStock('Pool');
        $pool->stockQuantity = 1;
        self::assertFalse($pool->isLowStock());

        $pool->stockQuantity = null;
        $pool->lowStockThreshold = 5;
        self::assertFalse($pool->isLowStock());
    }

    public function testResponseExposesThresholdAndFlag(): void
    {
        $pool = new SharedStock('Pool');
        $pool->stockQuantity = 3;
        $pool->lowStockThreshold = 5;

        $response = SharedStockResponse::fromEntity($pool);

        self::assertSame(5, $response->lowStockThreshold);
        self::assertTrue($response->lowStock);
    }
}
