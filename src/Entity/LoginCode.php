<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One-time email login code. Only a keyed hash of the code is stored. */
#[ORM\Entity(repositoryClass: LoginCodeRepository::class)]
#[ORM\Table(name: 'login_code')]
#[ORM\Index(name: 'idx_login_code_email_created', columns: ['email', 'created_at'])]
class LoginCode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 255)]
    public string $email;

    #[ORM\Column(length: 64)]
    public string $codeHash;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'smallint')]
    public int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    public function __construct(string $email, string $codeHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
    }
}
