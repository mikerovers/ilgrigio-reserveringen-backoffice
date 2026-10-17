<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Coupon;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
