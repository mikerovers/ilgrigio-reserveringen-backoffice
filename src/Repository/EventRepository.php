<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    public function findOneBySlug(string $slug): ?Event
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Upcoming events (start in the future), soonest first.
     *
     * @return Event[]
     */
    public function findUpcoming(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.startsAt >= :now')
            ->setParameter('now', $now)
            ->orderBy('e.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
