<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Order;
use App\Entity\Ticket;

final class OrderTicketsResponse
{
    /**
     * @param TicketResponse[] $tickets
     */
    public function __construct(
        public string $orderNumber,
        public array $tickets,
    ) {
    }

    /**
     * @param Ticket[] $tickets
     */
    public static function fromEntities(Order $order, array $tickets): self
    {
        return new self(
            orderNumber: $order->orderNumber,
            tickets: array_map(TicketResponse::fromEntity(...), $tickets),
        );
    }
}
