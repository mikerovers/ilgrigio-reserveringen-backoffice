<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customer')]
#[ORM\UniqueConstraint(name: 'uniq_customer_email', columns: ['email'])]
class Customer implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    /** Optional: register sales may be anonymous. Unique when set; Postgres allows many NULLs. */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    public ?string $email;

    #[ORM\Column(length: 255)]
    public string $firstName;

    #[ORM\Column(length: 255)]
    public string $lastName;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $companyName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $city = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $phoneNumber = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Order> */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'customer')]
    public Collection $orders;

    public function __construct(?string $email, string $firstName, string $lastName)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->orders = new ArrayCollection();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_CUSTOMER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? (string) $this->id;
    }

    public function eraseCredentials(): void
    {
    }

    public function __toString(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
