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
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[ORM\Table(name: 'coupon')]
#[ORM\UniqueConstraint(name: 'uniq_coupon_code', columns: ['code'])]
class Coupon
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    public string $code;

    #[ORM\Column(enumType: DiscountType::class)]
    public DiscountType $discountType = DiscountType::PERCENT;

    /** Basis points for PERCENT (1000 = 10%), cents for FIXED_CART. */
    #[ORM\Column(type: 'integer')]
    public int $amount = 0;

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

    public function getUsedCount(): int
    {
        return $this->orders->count();
    }
}
