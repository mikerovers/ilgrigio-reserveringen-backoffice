<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\OrderListQuery;
use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

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

    public function findOneByIdempotencyKey(string $key): ?Order
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    /**
     * @return Paginator<Order>
     */
    public function findPaginated(OrderListQuery $query): Paginator
    {
        $direction = $query->orderDirection === 'ASC' ? SortDirection::Ascending : SortDirection::Descending;
        $qb = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->join('o.customer', 'c')
            ->orderBy('o.createdAt', $direction)
            ->addOrderBy('o.id', $direction)
            ->setFirstResult(($query->page - 1) * $query->limit)
            ->setMaxResults($query->limit);

        // Every word must match the order number or the customer's first/last name.
        $words = preg_split('/\s+/', trim((string) $query->search), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $i => $word) {
            $param = 'search' . $i;
            $qb->andWhere(sprintf(
                "(LOWER(o.orderNumber) LIKE :%1\$s ESCAPE '!' OR LOWER(c.firstName) LIKE :%1\$s ESCAPE '!' OR LOWER(c.lastName) LIKE :%1\$s ESCAPE '!')",
                $param,
            ))->setParameter($param, '%' . mb_strtolower((string) preg_replace('/[!%_]/', '!$0', $word)) . '%');
        }

        return new Paginator($qb, fetchJoinCollection: false);
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
             WHERE o.created_at >= :start
               AND o.created_at < :end
               AND o.status = :status',
            [
                'start' => sprintf('%d-01-01 00:00:00', $year),
                'end' => sprintf('%d-01-01 00:00:00', $year + 1),
                'status' => OrderStatus::COMPLETED->value,
            ],
        );

        return (int) $result;
    }

    /**
     * Tickets sold (via completed orders) per year, newest year first.
     * Years without sales are omitted.
     *
     * @return array<int, int> year => tickets sold
     */
    public function getTicketsSoldPerYear(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT EXTRACT(YEAR FROM o.created_at)::int AS year, COUNT(t.id) AS tickets_sold
             FROM customer_order o
             INNER JOIN ticket t ON t.order_id = o.id
             WHERE o.status = :status
             GROUP BY year
             ORDER BY year DESC',
            ['status' => OrderStatus::COMPLETED->value],
        );

        $result = [];
        foreach ($rows as $y => $count) {
            $result[(int) $y] = (int) $count;
        }

        return $result;
    }
}
