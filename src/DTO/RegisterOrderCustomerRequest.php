<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterOrderCustomerRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $firstName = '',
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $lastName = '',
        #[Assert\Length(max: 255)]
        public ?string $companyName = null,
        #[Assert\Length(max: 255)]
        public ?string $city = null,
        #[Assert\Length(max: 50)]
        public ?string $phoneNumber = null,
    ) {
    }
}
