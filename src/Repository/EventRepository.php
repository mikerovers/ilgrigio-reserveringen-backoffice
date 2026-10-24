<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\EventListQuery;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
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
     * Upcoming events (start in the future) that are inside their sale window
     * (empty bounds are open-ended), soonest first. Fetch-joins ticket
     * types, shared stock and the display stock to avoid N+1 lazy-loads when the
     * caller reads stockStatus()/stockQuantity()/displayStock* per event.
     *
     * @return Event[]
     */
    public function findUpcoming(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.ticketTypes', 't')->addSelect('t')
            ->leftJoin('t.sharedStock', 's')->addSelect('s')
            ->leftJoin('e.displayStock', 'd')->addSelect('d')
            ->andWhere('e.startsAt >= :now')
            ->andWhere('e.soldFrom IS NULL OR e.soldFrom <= :now')
            ->andWhere('e.soldUntil IS NULL OR e.soldUntil >= :now')
            ->setParameter('now', $now)
            ->orderBy('e.startsAt', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated event listing, limited to events inside their sale window,
     * with optional category filter, fetch-joining
     * ticket types/shared stock/categories in a single query to avoid N+1
     * lazy-loads when building EventListResponse for a page of events.
     *
     * Uses Doctrine's fetch-join-aware Paginator rather than a naive COUNT,
     * since a plain count query would overcount rows duplicated by the
     * to-many joins.
     */
    public function findPaginated(EventListQuery $query, \DateTimeImmutable $now): Paginator
    {
        $direction = $query->orderDirection === 'ASC' ? SortDirection::Ascending : SortDirection::Descending;
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.ticketTypes', 't')->addSelect('t')
            ->leftJoin('t.sharedStock', 's')->addSelect('s')
            ->leftJoin('e.categories', 'c')->addSelect('c')
            ->andWhere('e.soldFrom IS NULL OR e.soldFrom <= :now')
            ->andWhere('e.soldUntil IS NULL OR e.soldUntil >= :now')
            ->setParameter('now', $now)
            ->orderBy('e.startsAt', $direction)
            ->setFirstResult(($query->page - 1) * $query->limit)
            ->setMaxResults($query->limit);

        if (!$query->includePast) {
            $qb->andWhere('e.startsAt >= :now');
        }

        if ($query->categoryId !== null) {
            $qb->andWhere(':categoryId MEMBER OF e.categories')
                ->setParameter('categoryId', Uuid::fromString($query->categoryId));
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: true);
    }
}
