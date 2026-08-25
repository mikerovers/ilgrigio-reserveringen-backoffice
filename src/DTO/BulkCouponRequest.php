<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\DiscountType;
use Symfony\Component\Validator\Constraints as Assert;

final class BulkCouponRequest
{
    public function __construct(
        public DiscountType $discountType = DiscountType::PERCENT,
        #[Assert\Positive(message: 'Amount must be greater than zero')]
        public int $amount = 0,
        public ?\DateTimeImmutable $expiresAt = null,
        public bool $active = true,
        public ?string $description = null,
        #[Assert\NotBlank(message: 'Add at least one coupon code')]
        public string $manualCodes = '',
    ) {
    }

    #[Assert\IsTrue(message: 'Percentage amount cannot be higher than 100')]
    public function isPercentageAmountValid(): bool
    {
        return $this->discountType !== DiscountType::PERCENT || $this->amount <= 100;
    }

    /**
     * @return string[]
     */
    public function parseManualCodes(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->manualCodes) ?: [];
        $codes = array_map(trim(...), $lines);

        return array_values(array_filter($codes, static fn (string $code): bool => $code !== ''));
    }
}
