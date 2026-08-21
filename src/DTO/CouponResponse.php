<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\DiscountType;

/**
 * Result of validating/removing a coupon, serialized to JSON by $this->json().
 * All money is in integer cents.
 */
final class CouponResponse
{
    public function __construct(
        public bool $valid,
        public ?string $code = null,
        public ?DiscountType $discountType = null,
        public int $amount = 0,
        public int $discountCents = 0,
        public int $newTotalCents = 0,
        public ?string $message = null,
        public bool $success = true,
    ) {
    }

    public static function invalid(string $message): self
    {
        return new self(valid: false, message: $message, success: false);
    }
}
