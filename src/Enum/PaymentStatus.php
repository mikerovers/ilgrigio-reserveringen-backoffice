<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Mirrors the Mollie payment status vocabulary.
 */
enum PaymentStatus: string
{
    case OPEN = 'open';
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    case FAILED = 'failed';

    public function isSuccessful(): bool
    {
        return $this === self::PAID;
    }

    public function isPending(): bool
    {
        return in_array($this, [self::OPEN, self::PENDING], true);
    }

    public function isFailed(): bool
    {
        return in_array($this, [self::CANCELED, self::EXPIRED, self::FAILED], true);
    }

    /**
     * Derive the order status from the Mollie payment status.
     */
    public function toOrderStatus(): OrderStatus
    {
        return match ($this) {
            self::PAID => OrderStatus::COMPLETED,
            self::OPEN, self::PENDING => OrderStatus::PENDING,
            self::CANCELED, self::EXPIRED, self::FAILED => OrderStatus::FAILED,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN, self::PENDING => 'Betaling in behandeling',
            self::PAID => 'Betaling voltooid',
            self::CANCELED => 'Betaling geannuleerd',
            self::EXPIRED => 'Betaling verlopen',
            self::FAILED => 'Betaling mislukt',
        };
    }
}
