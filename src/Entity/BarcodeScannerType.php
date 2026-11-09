<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BarcodeScannerTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BarcodeScannerTypeRepository::class)]
#[ORM\Table(name: 'barcode_scanner_type')]
#[ORM\UniqueConstraint(name: 'uniq_barcode_scanner_type_slug', columns: ['slug'])]
class BarcodeScannerType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(length: 255, unique: true)]
    #[Gedmo\Slug(fields: ['name'])]
    public ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    public ?string $manufacturer = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $notes = null;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    public int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, ScannerProgrammingBarcode> */
    #[ORM\OneToMany(targetEntity: ScannerProgrammingBarcode::class, mappedBy: 'scannerType', cascade: ['persist'])]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    public Collection $barcodes;

    public function __construct(string $name = '')
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->barcodes = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
