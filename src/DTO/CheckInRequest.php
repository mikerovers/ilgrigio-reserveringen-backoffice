<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CheckInRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Ticket code is required', normalizer: 'trim')]
        #[Assert\Length(max: 30, min: 10)]
        public string $ticketCode,
    ) {
    }
}
