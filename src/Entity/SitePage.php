<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SitePageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SitePageRepository::class)]
#[ORM\Table(name: 'site_page')]
#[ORM\UniqueConstraint(name: 'uniq_site_page_key', columns: ['page_key'])]
class SitePage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(name: 'page_key', length: 100)]
    public string $pageKey;

    #[ORM\Column(length: 255)]
    public string $heroTitle;

    #[ORM\Column(length: 255)]
    public string $heroDateText;

    #[ORM\Column(type: 'text')]
    public string $heroDescription;

    public function __construct(
        string $pageKey,
        string $heroTitle,
        string $heroDateText,
        string $heroDescription,
    ) {
        $this->id = Uuid::v7();
        $this->pageKey = $pageKey;
        $this->heroTitle = $heroTitle;
        $this->heroDateText = $heroDateText;
        $this->heroDescription = $heroDescription;
    }

    public function __toString(): string
    {
        return $this->heroTitle;
    }
}
