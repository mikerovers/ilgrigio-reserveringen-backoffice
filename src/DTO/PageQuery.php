<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pagination and createdAt ordering shared by the register list endpoints
 * (orders, order notes), populated via #[MapQueryString].
 */
final class PageQuery
{
    public function __construct(
        #[Assert\Range(min: 1, notInRangeMessage: 'page must be at least {{ min }}')]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'limit must be between {{ min }} and {{ max }}')]
        public int $limit = 20,
        #[Assert\Choice(choices: ['ASC', 'DESC'], message: 'orderDirection must be ASC or DESC')]
        public string $orderDirection = 'DESC',
    ) {
    }
}
