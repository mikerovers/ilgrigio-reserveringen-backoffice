<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\RegisterDiscountType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Ad-hoc discount given at the register. `amount` is basis points for
 * `percent` (1000 = 10%) and cents for `fixed`.
 */
final class RegisterOrderDiscountRequest
{
    public function __construct(
        public RegisterDiscountType $type = RegisterDiscountType::PERCENT,
        #[Assert\Positive(message: 'amount must be greater than zero')]
        public int $amount = 0,
        #[Assert\NotBlank(message: 'reason is required', normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $reason = '',
    ) {
    }

    #[Assert\Callback]
    public function validateAmount(ExecutionContextInterface $context): void
    {
        if ($this->type === RegisterDiscountType::PERCENT && $this->amount > 10000) {
            $context->buildViolation('A percentage cannot exceed 100% (10000 basis points).')
                ->atPath('amount')
                ->addViolation();
        }
    }
}
