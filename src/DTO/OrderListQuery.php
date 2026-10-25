<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class OrderListQuery
{
    public function __construct(
        #[Assert\Length(max: 64)]
        public ?string $search = null,
        #[Assert\Uuid(message: 'eventId must be a valid UUID')]
        public ?string $eventId = null,
        #[Assert\Range(min: 1, notInRangeMessage: 'page must be at least {{ min }}')]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'limit must be between {{ min }} and {{ max }}')]
        public int $limit = 20,
        #[Assert\Choice(choices: ['ASC', 'DESC'], message: 'orderDirection must be ASC or DESC')]
        public string $orderDirection = 'DESC',
    ) {
    }
}
