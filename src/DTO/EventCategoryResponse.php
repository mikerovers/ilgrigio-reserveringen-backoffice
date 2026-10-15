<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\EventCategory;

final class EventCategoryResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {
    }

    public static function fromEntity(EventCategory $category): self
    {
        return new self(
            id: (string) $category->id,
            name: $category->name,
            slug: (string) $category->slug,
        );
    }
}
