<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Ticket;
use App\Service\CheckInResult;

/**
 * Real-time check-in notification broadcast over Mercure to every scanner
 * subscribed to the event.
 *
 * Deliberately carries no ticket code: a broadcast must not expose valid QR payloads.
 */
final class CheckInUpdate implements \JsonSerializable
{
    public function __construct(
        public readonly CheckInResult $result,
        public readonly string $ticketName,
        public readonly string $orderNumber,
        public readonly ?string $checkedInAt,
    ) {
    }

    public static function fromTicket(CheckInResult $result, Ticket $ticket): self
    {
        return new self(
            result: $result,
            ticketName: $ticket->ticketName,
            orderNumber: $ticket->order->orderNumber,
            checkedInAt: $ticket->checkedInAt?->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array{result: string, ticketName: string, orderNumber: string, checkedInAt: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'result' => $this->result->name,
            'ticketName' => $this->ticketName,
            'orderNumber' => $this->orderNumber,
            'checkedInAt' => $this->checkedInAt,
        ];
    }
}
