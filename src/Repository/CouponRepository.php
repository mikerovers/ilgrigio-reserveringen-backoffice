<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\CouponListQuery;
use App\Entity\Coupon;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Coupon>
 */
class CouponRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coupon::class);
    }

    public function findOneByCode(string $code): ?Coupon
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return Paginator<Coupon>
     */
    public function findPaginated(CouponListQuery $query): Paginator
    {
        $qb = $this->createListQueryBuilder($query)
            ->setFirstResult(($query->page - 1) * $query->limit)
            ->setMaxResults($query->limit);

        return new Paginator($qb, fetchJoinCollection: false);
    }

    /**
     * Filtered and ordered list query without limits (page and limit are left to the caller).
     */
    public function createListQueryBuilder(CouponListQuery $query): QueryBuilder
    {
        $direction = $query->orderDirection === 'ASC' ? SortDirection::Ascending : SortDirection::Descending;
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', $direction)
            ->addOrderBy('c.id', $direction);

        if ($query->search !== null && $query->search !== '') {
            $escaped = preg_replace('/[!%_]/', '!$0', $query->search);
            $qb->andWhere("LOWER(c.code) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%' . mb_strtolower($escaped) . '%');
        }

        return $qb;
    }

    /**
     * Orders currently holding a redemption of this coupon.
     */
    public function countRedemptions(Coupon $coupon): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT COUNT(o.id) FROM App\Entity\Order o WHERE o.coupon = :coupon AND o.status IN (:statuses)',
        )
            ->setParameter('coupon', $coupon->id, 'uuid')
            ->setParameter('statuses', array_filter(
                OrderStatus::cases(),
                static fn (OrderStatus $s): bool => $s->holdsCouponRedemption(),
            ))
            ->getSingleScalarResult();
    }

    public function isExhausted(Coupon $coupon): bool
    {
        return $coupon->usageLimit !== null && $this->countRedemptions($coupon) >= $coupon->usageLimit;
    }

    /**
     * Take a row lock on the coupon so concurrent checkouts serialise their
     * limit check. Must be called inside the order-creating transaction.
     */
    public function lockForRedemption(Coupon $coupon): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id FROM coupon WHERE id = :id FOR UPDATE',
            ['id' => $coupon->id->toRfc4122()],
        );
    }
}
