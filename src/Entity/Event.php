<?php

declare(strict_types=1);

namespace App\Entity;

use SortDirection;
use App\Enum\StockStatus;
use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\Index(name: 'idx_event_starts_at', columns: ['starts_at'])]
#[ORM\UniqueConstraint(name: 'uniq_event_slug', columns: ['slug'])]
class Event
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 255)]
    public ?string $description = null;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'doors_open_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $doorsOpenAt = null;

    #[ORM\Column(name: 'sold_from', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $soldFrom = null;

    #[ORM\Column(name: 'sold_until', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $soldUntil = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $location = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    public ?string $slug = null;

    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $imageUrl = null;

    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $permalink = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, TicketType> */
    #[ORM\OneToMany(targetEntity: TicketType::class, mappedBy: 'event', cascade: ['persist'])]
    #[ORM\OrderBy(['sortOrder' => SortDirection::Ascending])]
    public Collection $ticketTypes;

    /** @var Collection<int, EventCategory> */
    #[ORM\ManyToMany(targetEntity: EventCategory::class, inversedBy: 'events')]
    #[ORM\JoinTable(name: 'event_event_category')]
    public Collection $categories;

    /**
     * Shared stock pool whose status and quantity drive the low stock and sold out display for this event.
     * Null falls back to the aggregate across all pools.
     */
    #[ORM\ManyToOne(targetEntity: SharedStock::class)]
    #[ORM\JoinColumn(name: 'display_stock_id', nullable: true, onDelete: 'SET NULL')]
    public ?SharedStock $displayStock = null;

    public function __construct(string $name, \DateTimeImmutable $startsAt)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->startsAt = $startsAt;
        $this->ticketTypes = new ArrayCollection();
        $this->categories = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Whether tickets may be sold at the given moment. Empty bounds are open-ended.
     */
    public function isOnSale(\DateTimeImmutable $now): bool
    {
        return ($this->soldFrom === null || $now >= $this->soldFrom)
            && ($this->soldUntil === null || $now <= $this->soldUntil);
    }

    /**
     * Derived from ticket types: IN_STOCK if any type has stock available, OUT_OF_STOCK otherwise.
     * Shared-stock pools are deduplicated so a pool shared by multiple types counts once.
     */
    /**
     * Derived from shared stock pools: IN_STOCK if any pool has stock available.
     */
    public function stockStatus(): StockStatus
    {
        $checkedPools = [];

        foreach ($this->ticketTypes as $ticketType) {
            if ($ticketType->sharedStock === null) {
                continue;
            }
            $poolId = (string) $ticketType->sharedStock->id;
            if (isset($checkedPools[$poolId])) {
                continue;
            }
            $checkedPools[$poolId] = true;
            if ($ticketType->sharedStock->stockStatus === StockStatus::IN_STOCK) {
                return StockStatus::IN_STOCK;
            }
        }

        return StockStatus::OUT_OF_STOCK;
    }

    /**
     * Minimum remaining quantity across all shared stock pools for this event.
     * Returns null if no pool tracks quantity.
     */
    public function stockQuantity(): ?int
    {
        $min = null;
        $checkedPools = [];

        foreach ($this->ticketTypes as $ticketType) {
            if ($ticketType->sharedStock === null) {
                continue;
            }
            $poolId = (string) $ticketType->sharedStock->id;
            if (isset($checkedPools[$poolId])) {
                continue;
            }
            $checkedPools[$poolId] = true;
            $qty = $ticketType->sharedStock->stockQuantity;
            if ($qty !== null) {
                $min = $min === null ? $qty : min($min, $qty);
            }
        }

        return $min;
    }

    /**
     * Stock status shown for this event: the display stock's status when one is chosen, otherwise stockStatus().
     */
    public function displayStockStatus(): StockStatus
    {
        return $this->displayStock !== null
            ? $this->displayStock->stockStatus
            : $this->stockStatus();
    }

    /**
     * Quantity shown for this event: the display stock's quantity when one is chosen, otherwise stockQuantity().
     */
    public function displayStockQuantity(): ?int
    {
        return $this->displayStock !== null
            ? $this->displayStock->stockQuantity
            : $this->stockQuantity();
    }

    /**
     * Distinct shared stock pools referenced by this event's ticket types,
     * deduplicated the same way as stockStatus()/stockQuantity().
     *
     * @return SharedStock[]
     */
    public function sharedStocks(): array
    {
        $pools = [];

        foreach ($this->ticketTypes as $ticketType) {
            if ($ticketType->sharedStock === null) {
                continue;
            }
            $pools[(string) $ticketType->sharedStock->id] = $ticketType->sharedStock;
        }

        return array_values($pools);
    }
}
