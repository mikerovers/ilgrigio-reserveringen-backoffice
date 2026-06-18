<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\CheckInResult;

/**
 * Result of a door check-in, serialized to JSON by $this->json().
 */
final class CheckInResponse
{
    public function __construct(
        public CheckInResult $result,
        public string $ticketCode,
        public ?string $ticketName = null,
        public ?string $checkedInAt = null,
    ) {
    }
}
