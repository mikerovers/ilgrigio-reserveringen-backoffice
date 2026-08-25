<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\OrderRepository;
use App\Service\SalesStatisticsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SalesStatisticsServiceTest extends TestCase
{
    private OrderRepository&MockObject $orderRepository;
    private SalesStatisticsService $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->service = new SalesStatisticsService($this->orderRepository);
    }

    public function testGetAnnualTicketsSoldDelegatesToRepository(): void
    {
        $this->orderRepository
            ->expects($this->once())
            ->method('getAnnualTicketsSold')
            ->with(2025)
            ->willReturn(300);

        $result = $this->service->getAnnualTicketsSold(2025);

        $this->assertSame(300, $result);
    }

    public function testGetAnnualTicketsSoldReturnsZeroWhenNoOrders(): void
    {
        $this->orderRepository
            ->method('getAnnualTicketsSold')
            ->willReturn(0);

        $this->assertSame(0, $this->service->getAnnualTicketsSold(2020));
    }

    public function testGetYearsWithSalesDelegatesToRepository(): void
    {
        $this->orderRepository
            ->expects($this->once())
            ->method('getYearsWithCompletedOrders')
            ->willReturn([2026, 2025]);

        $this->assertSame([2026, 2025], $this->service->getYearsWithSales());
    }
}
