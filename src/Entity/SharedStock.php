<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockStatus;
use App\Repository\SharedStockRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SharedStockRepository::class)]
#[ORM\Table(name: 'shared_stock')]
class SharedStock
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $stockQuantity = null;

    #[ORM\Column(enumType: StockStatus::class, options: ['default' => 'instock'])]
    public StockStatus $stockStatus = StockStatus::IN_STOCK;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function decrementStock(int $qty): void
    {
        if ($this->stockQuantity === null) {
            return;
        }

        $this->stockQuantity = max(0, $this->stockQuantity - $qty);

        if ($this->stockQuantity <= 0) {
            $this->stockStatus = StockStatus::OUT_OF_STOCK;
        }
    }
}
