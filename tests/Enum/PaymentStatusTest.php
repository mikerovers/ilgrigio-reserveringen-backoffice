<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\OrderStatus;
use App\Enum\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function testPaidMapsToCompleted(): void
    {
        $this->assertSame(OrderStatus::COMPLETED, PaymentStatus::PAID->toOrderStatus());
    }

    public function testOpenAndPendingMapToPending(): void
    {
        $this->assertSame(OrderStatus::PENDING, PaymentStatus::OPEN->toOrderStatus());
        $this->assertSame(OrderStatus::PENDING, PaymentStatus::PENDING->toOrderStatus());
    }

    public function testFailureStatesMapToFailed(): void
    {
        $this->assertSame(OrderStatus::FAILED, PaymentStatus::CANCELED->toOrderStatus());
        $this->assertSame(OrderStatus::FAILED, PaymentStatus::EXPIRED->toOrderStatus());
        $this->assertSame(OrderStatus::FAILED, PaymentStatus::FAILED->toOrderStatus());
    }

    public function testHelperPredicates(): void
    {
        $this->assertTrue(PaymentStatus::PAID->isSuccessful());
        $this->assertTrue(PaymentStatus::OPEN->isPending());
        $this->assertTrue(PaymentStatus::EXPIRED->isFailed());
    }

    public function testOnlyCompletedOrderTriggersFulfilment(): void
    {
        $this->assertTrue(OrderStatus::COMPLETED->triggersFulfilment());
        $this->assertFalse(OrderStatus::PENDING->triggersFulfilment());
    }
}
