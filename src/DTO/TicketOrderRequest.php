<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ticket selection submission from the event page. The coupon is referenced by
 * code and re-validated server-side (never trusted from the client). Populated
 * from the form-encoded POST body via #[MapRequestPayload].
 */
final class TicketOrderRequest
{
    public function __construct(
        /** @var array<int|string, array{quantity?: int|string, name?: string, price?: int|string}> */
        #[Assert\NotBlank(message: 'Tickets zijn verplicht')]
        #[Assert\Type('array', message: 'Tickets moeten een array zijn')]
        #[Assert\Count(min: 1, minMessage: 'Selecteer minimaal één ticket')]
        public array $tickets = [],
        public ?string $appliedCouponCode = null,
    ) {
    }
}
