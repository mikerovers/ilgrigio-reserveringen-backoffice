<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Door check-in request body (JSON), populated via #[MapRequestPayload].
 */
final class CheckInRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Ticket code is required')]
        public string $ticketCode = '',
    ) {
    }
}
