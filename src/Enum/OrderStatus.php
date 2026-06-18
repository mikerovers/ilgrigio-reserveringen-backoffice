<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';

    public function isFinal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::CANCELLED, self::FAILED, self::REFUNDED => true,
            default => false,
        };
    }

    /**
     * Only a completed order triggers ticket issuance, the confirmation email
     * and PDF generation.
     */
    public function triggersFulfilment(): bool
    {
        return $this === self::COMPLETED;
    }
}
