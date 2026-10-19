<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use App\Entity\OrderNote;

final class OrderNoteResponse
{
    public function __construct(
        public string $id,
        public string $content,
        #[OA\Property(description: 'When it was created. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public string $createdAt,
    ) {
    }

    public static function fromEntity(OrderNote $note): self
    {
        return new self(
            id: (string) $note->id,
            content: $note->content,
            createdAt: $note->createdAt->format(\DateTimeInterface::ATOM),
        );
    }
}
