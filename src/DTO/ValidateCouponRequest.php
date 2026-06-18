<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Coupon validation request. Populated from the JSON POST body via
 * #[MapRequestPayload], replacing the manual json_decode in validateCoupon.
 */
final class ValidateCouponRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Coupon code is required')]
        public string $code = '',
    ) {
    }
}
