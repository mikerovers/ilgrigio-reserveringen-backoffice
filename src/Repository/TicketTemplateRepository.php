<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TicketTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketTemplate>
 */
class TicketTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketTemplate::class);
    }

    public function findDefault(): ?TicketTemplate
    {
        return $this->findOneBy(['isDefault' => true]);
    }
}
