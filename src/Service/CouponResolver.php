<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Repository\CouponRepository;
use Psr\Clock\ClockInterface;

/**
 * Single place that decides whether a typed/stored coupon code can be used right
 * now: it must exist, be active, unexpired and not have hit its usage limit.
 */
class CouponResolver
{
    public function __construct(
        private CouponRepository $couponRepository,
        private DiscountCalculator $discountCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(?string $code): ?Coupon
    {
        $code = $code === null ? '' : trim($code);
        if ($code === '') {
            return null;
        }

        $coupon = $this->couponRepository->findOneByCode($code);
        if ($coupon === null || !$this->discountCalculator->isApplicable($coupon, $this->clock->now())) {
            return null;
        }

        return $this->couponRepository->isExhausted($coupon) ? null : $coupon;
    }
}
