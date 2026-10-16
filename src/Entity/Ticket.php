<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
#[ORM\Index(name: 'idx_ticket_event_code', columns: ['event_id', 'ticket_code'])]
#[ORM\Index(name: 'idx_ticket_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_ticket_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_ticket_code', columns: ['ticket_code'])]
#[ORM\UniqueConstraint(name: 'uniq_ticket_line_index', columns: ['line_item_id', 'line_index'])]
class Ticket
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    /** The QR payload. Unique, unguessable, app-generated. */
    #[ORM\Column(name: 'ticket_code', length: 64, unique: true)]
    public string $ticketCode;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Order $order;

    #[ORM\ManyToOne(targetEntity: OrderLineItem::class)]
    #[ORM\JoinColumn(nullable: false)]
    public OrderLineItem $lineItem;

    #[ORM\ManyToOne(targetEntity: TicketType::class)]
    #[ORM\JoinColumn(nullable: false)]
    public TicketType $ticketType;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Event $event;

    #[ORM\Column(name: 'ticket_name', length: 255)]
    public string $ticketName;

    // Snapshots so a scanned ticket renders without joins and survives edits.
    #[ORM\Column(name: 'event_name_snapshot', length: 255)]
    public string $eventNameSnapshot;

    #[ORM\Column(name: 'event_datetime_snapshot', type: 'datetime_immutable')]
    public \DateTimeImmutable $eventDateTimeSnapshot;

    #[ORM\Column(enumType: TicketStatus::class)]
    public TicketStatus $status = TicketStatus::ISSUED;

    #[ORM\Column(name: 'checked_in_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $checkedInAt = null;

    #[ORM\Column(name: 'checked_in_by', length: 255, nullable: true)]
    public ?string $checkedInBy = null;

    /** Position within its line item; with the line item it makes duplicate issuance fail at the DB. */
    #[ORM\Column(name: 'line_index', type: 'integer', options: ['default' => 0])]
    public int $lineIndex = 0;

    #[ORM\Column(name: 'issued_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $issuedAt;

    public function __construct(
        string $ticketCode,
        Order $order,
        OrderLineItem $lineItem,
        TicketType $ticketType,
        Event $event,
        string $ticketName,
        string $eventNameSnapshot,
        \DateTimeImmutable $eventDateTimeSnapshot,
        \DateTimeImmutable $issuedAt,
        int $lineIndex = 0,
    ) {
        $this->id = Uuid::v7();
        $this->ticketCode = $ticketCode;
        $this->order = $order;
        $this->lineItem = $lineItem;
        $this->ticketType = $ticketType;
        $this->event = $event;
        $this->ticketName = $ticketName;
        $this->eventNameSnapshot = $eventNameSnapshot;
        $this->eventDateTimeSnapshot = $eventDateTimeSnapshot;
        $this->issuedAt = $issuedAt;
        $this->lineIndex = $lineIndex;
    }
}
