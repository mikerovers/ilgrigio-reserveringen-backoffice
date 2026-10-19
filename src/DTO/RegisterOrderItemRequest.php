<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterOrderItemRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'ticketTypeId is required')]
        #[Assert\Uuid(message: 'ticketTypeId must be a valid UUID')]
        public string $ticketTypeId = '',
        #[Assert\Positive(message: 'quantity must be at least 1')]
        public int $quantity = 0,
    ) {
    }
}
