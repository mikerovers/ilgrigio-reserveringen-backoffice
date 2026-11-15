<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One rich-text part of a ticket template. `content` is sanitized HTML
 * (bold, bullet lists only); see TicketContentSanitizer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ticket_template_section')]
#[ORM\Index(name: 'idx_ticket_template_section_template', columns: ['template_id'])]
class TicketTemplateSection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: TicketTemplate::class, inversedBy: 'sections')]
    #[ORM\JoinColumn(name: 'template_id', nullable: false, onDelete: 'CASCADE')]
    public ?TicketTemplate $template = null;

    #[ORM\Column(type: 'integer')]
    public int $position = 0;

    #[ORM\Column(type: 'text')]
    public string $content = '';

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return trim(strip_tags($this->content)) !== '' ? mb_strimwidth(trim(strip_tags($this->content)), 0, 60, '…') : 'Section';
    }
}
