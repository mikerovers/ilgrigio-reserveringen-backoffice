<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BarcodeScannerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BarcodeScannerType>
 */
class BarcodeScannerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BarcodeScannerType::class);
    }

    /**
     * Active scanner types in the order they are offered on the scanbalie.
     *
     * @return BarcodeScannerType[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The default type for a new programming barcode: the first one offered.
     */
    public function findFirstOrdered(): ?BarcodeScannerType
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
