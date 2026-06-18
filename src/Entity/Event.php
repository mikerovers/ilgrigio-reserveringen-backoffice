<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockStatus;
use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
    public ?string $description = null;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $location = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    public ?string $slug = null;

    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $imageUrl = null;

    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $permalink = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $stockQuantity = null;

    #[ORM\Column(enumType: StockStatus::class, options: ['default' => 'instock'])]
    public StockStatus $stockStatus = StockStatus::IN_STOCK;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $lowStockAmount = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, TicketType> */
    #[ORM\OneToMany(targetEntity: TicketType::class, mappedBy: 'event', cascade: ['persist'])]
    public Collection $ticketTypes;

    /** @var Collection<int, EventCategory> */
    #[ORM\ManyToMany(targetEntity: EventCategory::class, inversedBy: 'events')]
    #[ORM\JoinTable(name: 'event_event_category')]
    public Collection $categories;

    public function __construct(string $name, \DateTimeImmutable $startsAt)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->startsAt = $startsAt;
        $this->ticketTypes = new ArrayCollection();
        $this->categories = new ArrayCollection();
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
