<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockReservationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Records how much of a shared stock pool an order has taken, so the stock can
 * be returned exactly once when the order fails, expires or is cancelled.
 */
#[ORM\Entity(repositoryClass: StockReservationRepository::class)]
#[ORM\Table(name: 'stock_reservation')]
#[ORM\Index(name: 'idx_stock_reservation_order', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'uniq_stock_reservation_order_pool', columns: ['order_id', 'shared_stock_id'])]
class StockReservation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Order $order;

    #[ORM\ManyToOne(targetEntity: SharedStock::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public SharedStock $sharedStock;

    #[ORM\Column(type: 'integer')]
    public int $quantity;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $releasedAt = null;

    public function __construct(Order $order, SharedStock $sharedStock, int $quantity, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->sharedStock = $sharedStock;
        $this->quantity = $quantity;
        $this->createdAt = $createdAt;
    }
}
