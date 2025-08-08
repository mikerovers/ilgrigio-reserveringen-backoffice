<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CheckoutDTO
{
    #[Assert\NotBlank(message: 'Voornaam is verplicht')]
    #[Assert\Length(max: 255, maxMessage: 'Voornaam mag maximaal 255 karakters bevatten')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Achternaam is verplicht')]
    #[Assert\Length(max: 255, maxMessage: 'Achternaam mag maximaal 255 karakters bevatten')]
    public string $lastName = '';

    #[Assert\Length(max: 255, maxMessage: 'Bedrijfsnaam mag maximaal 255 karakters bevatten')]
    public string $companyName = '';

    #[Assert\NotBlank(message: 'Stad is verplicht')]
    #[Assert\Length(max: 255, maxMessage: 'Stad mag maximaal 255 karakters bevatten')]
    public string $city = '';

    #[Assert\Length(max: 50, maxMessage: 'Telefoonnummer mag maximaal 50 karakters bevatten')]
    public string $phoneNumber = '';

    #[Assert\NotBlank(message: 'E-mailadres is verplicht')]
    #[Assert\Email(message: 'Voer een geldig e-mailadres in')]
    #[Assert\Length(max: 255, maxMessage: 'E-mailadres mag maximaal 255 karakters bevatten')]
    public string $email = '';

    #[Assert\NotBlank(message: 'E-mailbevestiging is verplicht')]
    #[Assert\Email(message: 'Voer een geldig e-mailadres in voor bevestiging')]
    public string $emailConfirm = '';

    #[Assert\IsTrue(message: 'Je moet akkoord gaan met de algemene voorwaarden')]
    public bool $terms = false;

    public function __construct(array $data = [])
    {
        $this->firstName = $data['firstName'] ?? '';
        $this->lastName = $data['lastName'] ?? '';
        $this->companyName = $data['companyName'] ?? '';
        $this->city = $data['city'] ?? '';
        $this->phoneNumber = $data['phoneNumber'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->emailConfirm = $data['emailConfirm'] ?? '';
        $this->terms = (bool) ($data['terms'] ?? false);
    }

    #[Assert\IsTrue(message: 'E-mailadressen komen niet overeen')]
    public function isEmailMatching(): bool
    {
        return $this->email === $this->emailConfirm;
    }
}
