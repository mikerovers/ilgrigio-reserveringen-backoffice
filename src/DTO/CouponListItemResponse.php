<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use App\Entity\Coupon;
use App\Enum\DiscountType;

final class CouponListItemResponse
{
    public function __construct(
        public string $id,
        public string $code,
        public DiscountType $discountType,
        public int $amount,
        public ?int $usageLimit,
        public int $usedCount,
        public ?string $description,
        public bool $active,
        #[OA\Property(description: 'When the coupon expires. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $expiresAt,
        #[OA\Property(description: 'When it was created. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public string $createdAt,
    ) {
    }

    public static function fromEntity(Coupon $coupon): self
    {
        return new self(
            id: (string) $coupon->id,
            code: $coupon->code,
            discountType: $coupon->discountType,
            amount: $coupon->amount,
            usageLimit: $coupon->usageLimit,
            usedCount: $coupon->getUsedCount(),
            description: $coupon->description,
            active: $coupon->active,
            expiresAt: $coupon->expiresAt?->format(\DateTimeInterface::ATOM),
            createdAt: $coupon->createdAt->format(\DateTimeInterface::ATOM),
        );
    }
}
