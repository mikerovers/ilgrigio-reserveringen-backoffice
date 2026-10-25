<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findOneByEmail(string $email): ?Customer
    {
        return $this->findOneBy(['email' => $email]);
    }

    /** Case-insensitive lookup used by the passwordless login. */
    public function findOneByEmailIgnoreCase(string $email): ?Customer
    {
        return $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
