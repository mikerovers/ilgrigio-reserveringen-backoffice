<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;
use App\Enum\OrderStatus;

final class OrderListItemResponse
{
    public function __construct(
        public string $id,
        public string $orderNumber,
        public OrderStatus $status,
        public string $customerName,
        public string $customerEmail,
        public int $totalCents,
        public ?string $couponCode,
        public ?string $paidAt,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(Order $order): self
    {
        return new self(
            id: (string) $order->id,
            orderNumber: $order->orderNumber,
            status: $order->status,
            customerName: (string) $order->customer,
            customerEmail: $order->customer->email,
            totalCents: (int) $order->totalGross->getAmount(),
            couponCode: $order->couponCode,
            paidAt: $order->paidAt?->format(\DateTimeInterface::ATOM),
            createdAt: $order->createdAt->format(\DateTimeInterface::ATOM),
        );
    }
}
