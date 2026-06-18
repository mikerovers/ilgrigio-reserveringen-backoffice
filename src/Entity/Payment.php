<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Money\Money;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_mollie_id', columns: ['mollie_payment_id'])]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\OneToOne(targetEntity: Order::class, inversedBy: 'payment')]
    #[ORM\JoinColumn(nullable: false)]
    public Order $order;

    /** "tr_xxx" from Mollie; null for zero-total orders that bypass payment. */
    #[ORM\Column(name: 'mollie_payment_id', length: 64, nullable: true, unique: true)]
    public ?string $molliePaymentId = null;

    #[ORM\Column(enumType: PaymentStatus::class)]
    public PaymentStatus $status = PaymentStatus::OPEN;

    #[ORM\Column(name: 'amount_cents', type: 'money')]
    public Money $amount;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    public string $currency = 'EUR';

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $method = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Order $order, Money $amount)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->amount = $amount;
    }
}
