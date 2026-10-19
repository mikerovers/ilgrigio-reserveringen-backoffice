<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\RegisterPaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateOrderRequest
{
    /**
     * @param RegisterOrderItemRequest[] $items
     */
    public function __construct(
        #[Assert\NotBlank(message: 'items are required')]
        #[Assert\Count(min: 1, minMessage: 'At least one item is required')]
        #[Assert\Valid]
        public array $items = [],
        #[Assert\Valid]
        public RegisterOrderCustomerRequest $customer = new RegisterOrderCustomerRequest(),
        public RegisterPaymentMethod $paymentMethod = RegisterPaymentMethod::CASH,
        #[Assert\Length(max: 64)]
        public ?string $couponCode = null,
        #[Assert\Valid]
        public ?RegisterOrderDiscountRequest $discount = null,
    ) {
    }
}
