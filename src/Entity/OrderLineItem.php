<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderLineItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Money\Money;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrderLineItemRepository::class)]
#[ORM\Table(name: 'order_line_item')]
#[ORM\Index(name: 'idx_line_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_line_ticket_type', columns: ['ticket_type_id'])]
class OrderLineItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lineItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Order $order;

    #[ORM\ManyToOne(targetEntity: TicketType::class)]
    #[ORM\JoinColumn(nullable: false)]
    public TicketType $ticketType;

    /** Snapshot of the ticket type name at purchase time. */
    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    public int $quantity = 1;

    #[ORM\Column(name: 'unit_net_cents', type: 'money')]
    public Money $unitNet;

    #[ORM\Column(name: 'unit_gross_cents', type: 'money')]
    public Money $unitGross;

    #[ORM\Column(name: 'line_net_cents', type: 'money')]
    public Money $lineNet;

    #[ORM\Column(name: 'line_tax_cents', type: 'money')]
    public Money $lineTax;

    #[ORM\Column(name: 'line_gross_cents', type: 'money')]
    public Money $lineGross;

    public function __construct(
        Order $order,
        TicketType $ticketType,
        string $name,
        int $quantity,
        Money $unitNet,
        Money $unitGross,
        Money $lineNet,
        Money $lineTax,
        Money $lineGross
    ) {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->ticketType = $ticketType;
        $this->name = $name;
        $this->quantity = $quantity;
        $this->unitNet = $unitNet;
        $this->unitGross = $unitGross;
        $this->lineNet = $lineNet;
        $this->lineTax = $lineTax;
        $this->lineGross = $lineGross;
    }
}
