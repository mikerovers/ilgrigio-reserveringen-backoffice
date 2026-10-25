<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\TicketType;

/**
 * Outcome of checking a ticket selection against the event's current state.
 */
final readonly class TicketSelectionResult
{
    /**
     * @param list<array{ticketType: TicketType, quantity: int}> $cart       valid, priced rows
     * @param array<string, string>                              $rowErrors  ticket type id => message
     * @param list<string>                                       $formErrors form-level messages
     */
    public function __construct(
        public array $cart,
        public array $rowErrors,
        public array $formErrors,
        public int $totalQuantity,
    ) {
    }

    public function isValid(): bool
    {
        return $this->rowErrors === [] && $this->formErrors === [];
    }
}
