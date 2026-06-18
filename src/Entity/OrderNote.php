<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderNoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrderNoteRepository::class)]
#[ORM\Table(name: 'order_note')]
#[ORM\Index(name: 'idx_note_order', columns: ['order_id'])]
class OrderNote
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Order $order;

    #[ORM\Column(type: 'text')]
    public string $content;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Order $order, string $content)
    {
        $this->id = Uuid::v7();
        $this->order = $order;
        $this->content = $content;
    }
}
