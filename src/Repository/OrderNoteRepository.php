<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\PageQuery;
use App\Entity\Order;
use App\Entity\OrderNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/** @extends ServiceEntityRepository<OrderNote> */
class OrderNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderNote::class);
    }

    /**
     * @return Paginator<OrderNote>
     */
    public function findByOrderPaginated(Order $order, PageQuery $query): Paginator
    {
        $direction = $query->orderDirection === 'ASC' ? SortDirection::Ascending : SortDirection::Descending;
        $qb = $this->createQueryBuilder('n')
            ->where('n.order = :order')
            ->setParameter('order', $order->id, 'uuid')
            ->orderBy('n.createdAt', $direction)
            ->addOrderBy('n.id', $direction)
            ->setFirstResult(($query->page - 1) * $query->limit)
            ->setMaxResults($query->limit);

        return new Paginator($qb, fetchJoinCollection: false);
    }
}
