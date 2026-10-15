<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Generic pagination envelope. PHP has no generics, so `items` is typed as a
 * plain array; callers are responsible for filling it with a single DTO type.
 */
final class PaginatedResponse
{
    public int $totalPages;

    /**
     * @param array<int, mixed> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $total,
    ) {
        $this->totalPages = $this->limit > 0 ? (int) ceil($this->total / $this->limit) : 0;
    }
}
