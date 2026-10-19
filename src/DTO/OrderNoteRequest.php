<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class OrderNoteRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'content is required', normalizer: 'trim')]
        #[Assert\Length(max: 5000)]
        public string $content = '',
    ) {
    }
}
