<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;

final class SalesStatisticsService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
    ) {
    }

    /**
     * Returns the total number of tickets sold in the given year.
     */
    public function getAnnualTicketsSold(int $year): int
    {
        return $this->orderRepository->getAnnualTicketsSold($year);
    }

    /**
     * Returns the distinct years (descending) that have at least one completed order.
     *
     * @return array<int, int>
     */
    public function getYearsWithSales(): array
    {
        return $this->orderRepository->getYearsWithCompletedOrders();
    }
}
