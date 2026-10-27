<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<LoginCode>
 */
class LoginCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginCode::class);
    }

    /** Latest code for the email that is unused and not yet expired. */
    public function findActiveByEmail(string $email, \DateTimeImmutable $now): ?LoginCode
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.email = :email')
            ->andWhere('l.usedAt IS NULL')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('now', $now)
            ->orderBy('l.createdAt', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Marks every still-open code for the email as used (a new code replaces older ones). */
    public function invalidateOpenCodes(string $email, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('l')
            ->update()
            ->set('l.usedAt', ':now')
            ->andWhere('l.email = :email')
            ->andWhere('l.usedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();
    }

    public function purgeOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.expiresAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
