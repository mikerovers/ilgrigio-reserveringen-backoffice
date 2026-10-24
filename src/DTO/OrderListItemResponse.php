<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;
use App\Entity\Order;
use App\Enum\OrderSource;
use App\Enum\OrderStatus;

final class OrderListItemResponse
{
    public function __construct(
        public string $id,
        public string $orderNumber,
        public OrderStatus $status,
        public OrderSource $source,
        public string $customerName,
        public string $customerFirstName,
        public string $customerLastName,
        public ?string $customerCity,
        public ?string $customerEmail,
        public ?string $note,
        public int $totalCents,
        public ?string $couponCode,
        #[OA\Property(description: 'When the order was paid. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
        public ?string $paidAt,
        #[OA\Property(description: 'When it was created. ISO 8601 with explicit UTC offset.', format: 'date-time', example: '2026-10-19T20:21:58+00:00')]
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
            customerName: (string) $order->customer,
            customerFirstName: $order->customer->firstName,
            customerLastName: $order->customer->lastName,
            customerCity: $order->customer->city,
            customerEmail: $order->customer->email,
            note: $order->note,
            totalCents: (int) $order->totalGross->getAmount(),
            couponCode: $order->couponCode,
            paidAt: $order->paidAt?->format(\DateTimeInterface::ATOM),
            createdAt: $order->createdAt->format(\DateTimeInterface::ATOM),
        );
    }
}
