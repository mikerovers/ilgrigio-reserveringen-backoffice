<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;

final class RegisterOrderResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $created,
    ) {
    }
}
