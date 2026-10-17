<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DiscountType;
use App\Repository\CouponRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[UniqueEntity(fields: ['code'], message: 'This coupon code already exists.')]
#[ORM\Table(name: 'coupon')]
#[ORM\UniqueConstraint(name: 'uniq_coupon_code', columns: ['code'])]
class Coupon
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank(message: 'Coupon code is required.')]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: '/^\S+$/', message: 'Coupon code cannot contain whitespace.')]
    public string $code;

    #[ORM\Column(enumType: DiscountType::class)]
    public DiscountType $discountType = DiscountType::PERCENT;

    /** Basis points for PERCENT (1000 = 10%), cents for FIXED_CART. */
    #[ORM\Column(type: 'integer')]
    #[Assert\Positive(message: 'Amount must be greater than zero.')]
    public int $amount = 0;

    /** Maximum number of non-failed orders that may use this code; null = unlimited. */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive(message: 'Usage limit must be greater than zero.')]
    public ?int $usageLimit = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $active = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Order> */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'coupon')]
    public Collection $orders;

    public function __construct(string $code, DiscountType $discountType, int $amount)
    {
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->discountType = $discountType;
        $this->amount = $amount;
        $this->orders = new ArrayCollection();
    }

    /** Orders that still hold a redemption (failed/cancelled/refunded ones give it back). */
    public function getUsedCount(): int
    {
        return $this->orders->filter(
            static fn (Order $order): bool => $order->status->holdsCouponRedemption(),
        )->count();
    }

    #[Assert\Callback]
    public function validateAmount(ExecutionContextInterface $context): void
    {
        if ($this->discountType === DiscountType::PERCENT && $this->amount > 10000) {
            $context->buildViolation('A percentage cannot exceed 100% (10000 basis points).')
                ->atPath('amount')
                ->addViolation();
        }
    }
}
