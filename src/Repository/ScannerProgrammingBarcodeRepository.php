<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScannerProgrammingBarcode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScannerProgrammingBarcode>
 */
class ScannerProgrammingBarcodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScannerProgrammingBarcode::class);
    }
}
