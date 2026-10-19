<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\RegisterDiscountType;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Ad-hoc discount given at the register. `amount` is a plain percentage for
 * `percent` (10 = 10%, 12.5 = 12.5%, at most two decimals) and whole cents for `fixed`.
 */
final class RegisterOrderDiscountRequest
{
    public function __construct(
        public RegisterDiscountType $type = RegisterDiscountType::PERCENT,
        #[Assert\Positive(message: 'amount must be greater than zero')]
        #[OA\Property(description: 'Percentage for type "percent" (10 = 10%, max 100, up to 2 decimals); whole cents for type "fixed" (500 = EUR 5.00)', type: 'number', example: 10)]
        public int|float $amount = 0,
        #[Assert\NotBlank(message: 'reason is required', normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $reason = '',
    ) {
    }

    /**
     * The amount in the units the pricing code uses: basis points (1000 = 10%)
     * for percent discounts, cents for fixed ones.
     */
    public function minorAmount(): int
    {
        return $this->type === RegisterDiscountType::PERCENT
            ? (int) round($this->amount * 100)
            : (int) round($this->amount);
    }

    #[Assert\Callback]
    public function validateAmount(ExecutionContextInterface $context): void
    {
        if ($this->type === RegisterDiscountType::PERCENT) {
            if ($this->amount > 100) {
                $context->buildViolation('A percentage cannot exceed 100.')->atPath('amount')->addViolation();
            } elseif (abs($this->amount * 100 - round($this->amount * 100)) > 1e-6) {
                $context->buildViolation('A percentage may have at most two decimals.')->atPath('amount')->addViolation();
            }

            return;
        }

        if (abs($this->amount - round($this->amount)) > 1e-6) {
            $context->buildViolation('A fixed amount must be a whole number of cents.')->atPath('amount')->addViolation();
        }
    }
}
