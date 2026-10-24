<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Ticket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ticket>
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    /**
     * Single-index lookup for door scanning.
     */
    public function findOneForCheckIn(Event $event, string $ticketCode): ?Ticket
    {
        return $this->findOneBy(['event' => $event, 'ticketCode' => $ticketCode]);
    }

    /**
     * All tickets issued for an order, in line-item order.
     *
     * @return Ticket[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.order = :order')
            ->setParameter('order', $order->id, 'uuid')
            ->orderBy('t.lineItem', 'ASC')
            ->addOrderBy('t.lineIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
