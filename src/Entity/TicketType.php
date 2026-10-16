<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Money\Money;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TicketTypeRepository::class)]
#[ORM\Table(name: 'ticket_type')]
#[ORM\Index(name: 'idx_ticket_type_event', columns: ['event_id'])]
class TicketType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'ticketTypes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Gedmo\SortableGroup]
    public Event $event;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(name: 'price_cents', type: 'money')]
    public Money $price;

    #[ORM\ManyToOne(targetEntity: SharedStock::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    public ?SharedStock $sharedStock = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $disabled = false;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $disabledReason = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Gedmo\SortablePosition]
    public int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Event $event, string $name, Money $price)
    {
        $this->id = Uuid::v7();
        $this->event = $event;
        $this->name = $name;
        $this->price = $price;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
