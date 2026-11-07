<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A ticket code typed or scanned at the door scanner app.
 */
final class ScanRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 64)]
        public string $ticketCode = '',
        /** Client-generated id, echoed in the Mercure update so a client can recognise its own scans. */
        #[Assert\Length(max: 64)]
        public ?string $scanId = null,
    ) {
    }
}
