<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

final class OrderListQuery
{
    /**
     * @param list<string>|null $status Filter by any of these statuses (`status[]=pending&status[]=completed`)
     */
    public function __construct(
        #[Assert\Length(max: 64)]
        public ?string $search = null,
        #[Assert\Uuid(message: 'eventId must be a valid UUID')]
        public ?string $eventId = null,
        #[Assert\All(new Assert\Choice(callback: [OrderStatus::class, 'values'], message: 'status must be one of: {{ choices }}'))]
        public ?array $status = null,
        #[Assert\Range(min: 1, notInRangeMessage: 'page must be at least {{ min }}')]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'limit must be between {{ min }} and {{ max }}')]
        public int $limit = 20,
        #[Assert\Choice(choices: ['ASC', 'DESC'], message: 'orderDirection must be ASC or DESC')]
        public string $orderDirection = 'DESC',
    ) {
    }

    /**
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        return array_map(OrderStatus::from(...), array_values($this->status ?? []));
    }
}
