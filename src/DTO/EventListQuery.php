<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query params for GET /api/events, populated via #[MapQueryString] and
 * validated automatically by Symfony before the controller runs.
 */
final class EventListQuery
{
    public function __construct(
        #[Assert\Uuid(message: 'categoryId must be a valid UUID')]
        public ?string $categoryId = null,
        public bool $includePast = false,
        #[Assert\Range(min: 1, notInRangeMessage: 'page must be at least {{ min }}')]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'limit must be between {{ min }} and {{ max }}')]
        public int $limit = 20,
        #[Assert\Choice(choices: ['ASC', 'DESC'], message: 'orderDirection must be ASC or DESC')]
        public string $orderDirection = 'ASC',
    ) {
    }
}
