<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Dispatched when an order transitions to COMPLETED. The single fan-out point
 * for fulfilment: ticket issuance + confirmation email/PDF. Carries only the
 * order id so the handler reads fresh state from the database.
 */
#[AsMessage('async')]
final class OrderCompleted
{
    public function __construct(
        public readonly string $orderId,
    ) {
    }
}
