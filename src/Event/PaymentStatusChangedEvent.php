<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Order;
use App\Enum\PaymentStatus;

final class PaymentStatusChangedEvent
{
    public function __construct(
        public readonly Order $order,
        public readonly ?PaymentStatus $previousStatus,
        public readonly PaymentStatus $newStatus,
    ) {
    }
}
