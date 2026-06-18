<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable snapshot of a billing or shipping address, captured on an Order at
 * purchase time. Shipping copies leave phone and email null.
 */
#[ORM\Embeddable]
final class Address
{
    #[ORM\Column(length: 255)]
    public string $firstName = '';

    #[ORM\Column(length: 255)]
    public string $lastName = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $companyName = null;

    #[ORM\Column(length: 255)]
    public string $city = '';

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $phoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $email = null;
}
