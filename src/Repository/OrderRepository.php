<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByOrderNumber(string $orderNumber): ?Order
    {
        return $this->findOneBy(['orderNumber' => $orderNumber]);
    }

    /**
     * Returns the distinct years (descending) that have at least one completed order.
     *
     * @return array<int, int>
     */
    public function getYearsWithCompletedOrders(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->fetchFirstColumn(
            'SELECT DISTINCT EXTRACT(YEAR FROM o.created_at) AS year
             FROM customer_order o
             WHERE o.status = :status
             ORDER BY year DESC',
            ['status' => OrderStatus::COMPLETED->value],
        );

        return array_map('intval', $rows);
    }

    /**
     * Returns the total number of tickets sold (via completed orders) in a given year.
     */
    public function getAnnualTicketsSold(int $year): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $result = $conn->fetchOne(
            'SELECT COUNT(t.id)
             FROM customer_order o
             INNER JOIN ticket t ON t.order_id = o.id
             WHERE EXTRACT(YEAR FROM o.created_at) = :year
               AND o.status = :status',
            ['year' => $year, 'status' => OrderStatus::COMPLETED->value],
        );

        return (int) $result;
    }
}
