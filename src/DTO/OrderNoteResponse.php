<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\OrderNote;

final class OrderNoteResponse
{
    public function __construct(
        public string $id,
        public string $content,
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
