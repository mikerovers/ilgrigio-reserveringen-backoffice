<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\StockReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockReservation>
 */
class StockReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockReservation::class);
    }

    /**
     * @return StockReservation[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->findBy(['order' => $order]);
    }

    /**
     * Flip released_at in a single statement. Only one concurrent caller sees
     * true, which makes releasing idempotent across retries and parallel jobs.
     */
    public function markReleased(StockReservation $reservation, \DateTimeImmutable $now): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE stock_reservation SET released_at = :now WHERE id = :id AND released_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $reservation->id->toRfc4122()],
        ) === 1;
    }

    /**
     * Inverse of markReleased(), used when a late payment re-takes the stock.
     */
    public function markHeld(StockReservation $reservation): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE stock_reservation SET released_at = NULL WHERE id = :id AND released_at IS NOT NULL',
            ['id' => $reservation->id->toRfc4122()],
        ) === 1;
    }
}
