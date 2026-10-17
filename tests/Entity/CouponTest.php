<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Order;
use App\Enum\DiscountType;
use App\Enum\OrderStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class CouponTest extends TestCase
{
    public function testUsedCountIgnoresFailedCancelledAndRefundedOrders(): void
    {
        $coupon = new Coupon('X', DiscountType::PERCENT, 1000);

        foreach (OrderStatus::cases() as $i => $status) {
            $order = new Order('N' . $i, new Customer('a@b.nl', 'A', 'B'), 900);
            $order->status = $status;
            $coupon->orders->add($order);
        }

        // pending, processing, completed
        $this->assertSame(3, $coupon->getUsedCount());
    }

    public function testPercentAboveHundredPercentIsAViolation(): void
    {
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->method('atPath')->willReturnSelf();
        $builder->expects($this->once())->method('addViolation');
        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())->method('buildViolation')->willReturn($builder);

        (new Coupon('X', DiscountType::PERCENT, 10001))->validateAmount($context);
    }

    public function testFixedAndValidPercentAmountsPass(): void
    {
        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        (new Coupon('X', DiscountType::PERCENT, 10000))->validateAmount($context);
        (new Coupon('Y', DiscountType::FIXED_CART, 50000))->validateAmount($context);
    }
}
