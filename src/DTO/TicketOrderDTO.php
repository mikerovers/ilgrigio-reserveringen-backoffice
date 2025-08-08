<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class TicketOrderDTO
{
    #[Assert\NotBlank(message: 'Tickets zijn verplicht')]
    #[Assert\Type('array', message: 'Tickets moeten een array zijn')]
    #[Assert\Count(min: 1, minMessage: 'Selecteer minimaal één ticket')]
    public array $tickets = [];

    public ?array $appliedCoupon = null;

    #[Assert\PositiveOrZero(message: 'Kortingsbedrag moet positief zijn')]
    public float $discountAmount = 0;

    public function __construct(array $data = [])
    {
        $this->tickets = $data['tickets'] ?? [];
        $this->appliedCoupon = isset($data['applied_coupon']) ? json_decode($data['applied_coupon'], true) : null;
        $this->discountAmount = (float) ($data['discount_amount'] ?? 0);
    }
}
