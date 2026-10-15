<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\EventListQuery;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Paginated event listing with optional category filter, fetch-joining
     * ticket types/shared stock/categories in a single query to avoid N+1
     * lazy-loads when building EventListResponse for a page of events.
     *
     * Uses Doctrine's fetch-join-aware Paginator rather than a naive COUNT,
     * since a plain count query would overcount rows duplicated by the
     * to-many joins.
     */
    public function findPaginated(EventListQuery $query, \DateTimeImmutable $now): Paginator
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.ticketTypes', 't')->addSelect('t')
            ->leftJoin('t.sharedStock', 's')->addSelect('s')
            ->leftJoin('e.categories', 'c')->addSelect('c')
            ->orderBy('e.startsAt', $query->orderDirection)
            ->setFirstResult(($query->page - 1) * $query->limit)
            ->setMaxResults($query->limit);

        if (!$query->includePast) {
            $qb->andWhere('e.startsAt >= :now')->setParameter('now', $now);
        }

        if ($query->categoryId !== null) {
            $qb->andWhere(':categoryId MEMBER OF e.categories')
                ->setParameter('categoryId', Uuid::fromString($query->categoryId));
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: true);
    }
}
