<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;
use App\Enum\OrderSource;
use App\Enum\OrderStatus;

final class OrderDetailResponse
{
    /**
     * @param OrderLineItemResponse[] $lineItems
     */
    public function __construct(
        public string $id,
        public string $orderNumber,
        public OrderStatus $status,
        public OrderSource $source,
        public string $currency,
        public int $subtotalNetCents,
        public int $taxCents,
        public int $discountGrossCents,
        public int $totalCents,
        public ?string $couponCode,
        public ?OrderDiscountResponse $discount,
        public ?string $paymentMethod,
        public ?string $paidAt,
        public string $customerName,
        public string $customerEmail,
        public array $lineItems,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(Order $order): self
    {
        return new self(
            id: (string) $order->id,
            orderNumber: $order->orderNumber,
            status: $order->status,
            source: $order->source ?? OrderSource::WEB,
            currency: $order->currency,
            subtotalNetCents: (int) $order->subtotalNet->getAmount(),
            taxCents: (int) $order->tax->getAmount(),
            discountGrossCents: (int) $order->discountGross->getAmount(),
            totalCents: (int) $order->totalGross->getAmount(),
            couponCode: $order->couponCode,
            discount: OrderDiscountResponse::fromEntity($order),
            paymentMethod: $order->payment?->method,
            paidAt: $order->paidAt?->format(\DateTimeInterface::ATOM),
            customerName: (string) $order->customer,
            customerEmail: $order->customer->email,
            lineItems: array_map(
                OrderLineItemResponse::fromEntity(...),
                $order->lineItems->toArray(),
            ),
            createdAt: ($order->createdAt ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );
    }
}
