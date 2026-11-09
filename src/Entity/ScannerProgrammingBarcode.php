<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Code128Set;
use App\Exception\InvalidBarcodePayloadException;
use App\Repository\ScannerProgrammingBarcodeRepository;
use App\Service\Code128Payload;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ScannerProgrammingBarcodeRepository::class)]
#[ORM\Table(name: 'scanner_programming_barcode')]
#[ORM\Index(name: 'idx_scanner_barcode_type', columns: ['scanner_type_id'])]
class ScannerProgrammingBarcode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    public Uuid $id;

    #[ORM\ManyToOne(targetEntity: BarcodeScannerType::class, inversedBy: 'barcodes')]
    #[ORM\JoinColumn(name: 'scanner_type_id', nullable: false, onDelete: 'CASCADE')]
    #[Gedmo\SortableGroup]
    public BarcodeScannerType $scannerType;

    #[ORM\Column(length: 255)]
    public string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column(type: 'text')]
    public string $payload = '';

    #[ORM\Column(name: 'code_set', enumType: Code128Set::class)]
    public Code128Set $codeSet = Code128Set::AUTO;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    #[Gedmo\SortablePosition]
    public int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'create')]
    public ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    #[Gedmo\Timestampable(on: 'update')]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(BarcodeScannerType $scannerType, string $label = '')
    {
        $this->id = Uuid::v7();
        $this->scannerType = $scannerType;
        $this->label = $label;
    }

    public function __toString(): string
    {
        return $this->label;
    }

    #[Assert\Callback]
    public function validatePayload(ExecutionContextInterface $context): void
    {
        try {
            Code128Payload::assertEncodable($this->payload, $this->codeSet);
        } catch (InvalidBarcodePayloadException $exception) {
            $context->buildViolation($exception->getMessage())
                ->atPath('payload')
                ->addViolation();
        }
    }
}
