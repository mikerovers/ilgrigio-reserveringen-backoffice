<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharedStock;
use App\Enum\StockStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SharedStock>
 */
class SharedStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedStock::class);
    }

    /**
     * Atomically take $quantity from a pool. The WHERE guard makes the check and
     * the decrement a single statement, so concurrent checkouts cannot both
     * claim the last tickets. Pools without a tracked quantity are unlimited.
     *
     * @return bool false when the pool does not have enough stock left
     */
    public function reserve(SharedStock $pool, int $quantity): bool
    {
        if ($pool->stockQuantity === null) {
            return true;
        }

        $affected = (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE shared_stock
                SET stock_quantity = stock_quantity - :qty,
                    stock_status = CASE WHEN stock_quantity - :qty <= 0 THEN :out ELSE stock_status END
              WHERE id = :id AND stock_quantity IS NOT NULL AND stock_quantity >= :qty',
            ['qty' => $quantity, 'out' => StockStatus::OUT_OF_STOCK->value, 'id' => $pool->id->toRfc4122()],
        );

        return $affected === 1;
    }

    /**
     * Atomically give $quantity back to a pool (inverse of reserve()).
     */
    public function release(SharedStock $pool, int $quantity): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE shared_stock
                SET stock_quantity = stock_quantity + :qty,
                    stock_status = CASE WHEN stock_quantity + :qty > 0 THEN :in ELSE stock_status END
              WHERE id = :id AND stock_quantity IS NOT NULL',
            ['qty' => $quantity, 'in' => StockStatus::IN_STOCK->value, 'id' => $pool->id->toRfc4122()],
        );
    }
}
