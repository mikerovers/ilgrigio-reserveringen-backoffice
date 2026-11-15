<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketTemplateRepository;
use SortDirection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The editable part of the ticket PDF: everything below the fixed header
 * (event, QR code, date, customer). Chosen per event; the template flagged
 * as default is used when an event has none.
 */
#[ORM\Entity(repositoryClass: TicketTemplateRepository::class)]
#[ORM\Table(name: 'ticket_template')]
#[ORM\UniqueConstraint(name: 'uniq_ticket_template_default', columns: ['is_default'], options: ['where' => 'is_default'])]
class TicketTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    public string $name;

    #[ORM\Column(name: 'is_default', type: 'boolean')]
    public bool $isDefault = false;

    /** @var Collection<int, TicketTemplateSection> */
    #[ORM\OneToMany(
        targetEntity: TicketTemplateSection::class,
        mappedBy: 'template',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    public Collection $sections;

    public function __construct(string $name = '')
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->sections = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function addSection(TicketTemplateSection $section): void
    {
        $section->template = $this;
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
        }
    }

    public function removeSection(TicketTemplateSection $section): void
    {
        $this->sections->removeElement($section);
    }
}
