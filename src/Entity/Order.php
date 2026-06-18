<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Money\Money;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: "customer_order")]
#[ORM\Index(name: "idx_order_status", columns: ["status"])]
#[ORM\Index(name: "idx_order_customer", columns: ["customer_id"])]
#[ORM\Index(name: "idx_order_created", columns: ["created_at"])]
#[ORM\UniqueConstraint(name: "uniq_order_number", columns: ["order_number"])]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(name: "order_number", length: 32, unique: true)]
    public string $orderNumber;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: "orders")]
    #[ORM\JoinColumn(nullable: false)]
    public Customer $customer;

    #[ORM\Column(enumType: OrderStatus::class)]
    public OrderStatus $status = OrderStatus::PENDING;

    #[ORM\Column(length: 3, options: ["default" => "EUR"])]
    public string $currency = "EUR";

    // --- Money snapshot (frozen at finalization). ---
    #[ORM\Column(name: "subtotal_net_cents", type: "money")]
    public Money $subtotalNet;

    #[ORM\Column(name: "tax_cents", type: "money")]
    public Money $tax;

    #[ORM\Column(name: "discount_gross_cents", type: "money")]
    public Money $discountGross;

    #[ORM\Column(name: "discount_tax_cents", type: "money")]
    public Money $discountTax;

    #[ORM\Column(name: "total_gross_cents", type: "money")]
    public Money $totalGross;

    /** 900 = 9.00% BTW. */
    #[
        ORM\Column(
            name: "tax_rate_bp",
            type: "integer",
            options: ["default" => 900],
        ),
    ]
    public int $taxRateBasisPoints;

    #[ORM\Column(name: "tax_label", length: 32, options: ["default" => "BTW"])]
    public string $taxLabel = "BTW";

    #[ORM\Column(name: "tax_rate_code", length: 32, nullable: true)]
    public ?string $taxRateCode = "NL-VAT-9";

    #[ORM\Embedded(class: Address::class, columnPrefix: "billing_")]
    public Address $billing;

    #[ORM\Embedded(class: Address::class, columnPrefix: "shipping_")]
    public Address $shipping;

    #[ORM\ManyToOne(targetEntity: Coupon::class, inversedBy: "orders")]
    #[ORM\JoinColumn(nullable: true)]
    public ?Coupon $coupon = null;

    #[ORM\Column(name: "coupon_code", length: 64, nullable: true)]
    public ?string $couponCode = null;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    public ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: "datetime_immutable")]
    #[Gedmo\Timestampable(on: "create")]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: "datetime_immutable")]
    #[Gedmo\Timestampable(on: "update")]
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, OrderLineItem> */
    #[
        ORM\OneToMany(
            targetEntity: OrderLineItem::class,
            mappedBy: "order",
            cascade: ["persist", "remove"],
            orphanRemoval: true,
        ),
    ]
    public Collection $lineItems;

    /** @var Collection<int, Ticket> */
    #[
        ORM\OneToMany(
            targetEntity: Ticket::class,
            mappedBy: "order",
            cascade: ["persist"],
        ),
    ]
    public Collection $tickets;

    /** @var Collection<int, OrderNote> */
    #[
        ORM\OneToMany(
            targetEntity: OrderNote::class,
            mappedBy: "order",
            cascade: ["persist", "remove"],
            orphanRemoval: true,
        ),
    ]
    public Collection $notes;

    #[
        ORM\OneToOne(
            targetEntity: Payment::class,
            mappedBy: "order",
            cascade: ["persist"],
        ),
    ]
    public ?Payment $payment = null;

    public function __construct(
        string $orderNumber,
        Customer $customer,
        int $taxRateBasisPoints,
    ) {
        $this->id = Uuid::v7();
        $this->orderNumber = $orderNumber;
        $this->customer = $customer;
        $this->taxRateBasisPoints = $taxRateBasisPoints;
        $this->billing = new Address();
        $this->shipping = new Address();
        $this->subtotalNet = Money::EUR(0);
        $this->tax = Money::EUR(0);
        $this->discountGross = Money::EUR(0);
        $this->discountTax = Money::EUR(0);
        $this->totalGross = Money::EUR(0);
        $this->lineItems = new ArrayCollection();
        $this->tickets = new ArrayCollection();
        $this->notes = new ArrayCollection();
    }

    /**
     * Transition the order to COMPLETED (paid + fulfilled).
     */
    public function completeNow(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::COMPLETED;
        $this->paidAt ??= $now;
        $this->completedAt = $now;
    }
}
