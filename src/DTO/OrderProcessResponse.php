<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Result of (re-)processing an order via the admin API, serialized to JSON.
 */
final class OrderProcessResponse
{
    public function __construct(
        public bool $success,
        public string $orderNumber,
        public string $message,
    ) {
    }
}
