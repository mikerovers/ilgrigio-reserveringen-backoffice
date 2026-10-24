<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<EventCategory>
 */
class EventCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventCategory::class);
    }

    public function findOneBySlug(string $slug): ?EventCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return EventCategory[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
