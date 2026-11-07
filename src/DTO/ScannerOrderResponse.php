<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\Ticket;
use App\Enum\TicketStatus;
use App\Service\VenueTime;

/**
 * An order with its tickets for the scanner's order popup: who bought it, how
 * many tickets are in already, and which can still be scanned at this show.
 */
final class ScannerOrderResponse
{
    /**
     * @param list<array{code: string, typeName: string, status: string, checkedInTime: ?string, scannable: bool}> $tickets
     */
    public function __construct(
        public string $orderNumber,
        public string $customerName,
        public string $eventDate,
        public int $total,
        public int $scanned,
        public array $tickets,
    ) {
    }

    /**
     * @param Ticket[] $tickets all tickets of the order
     */
    public static function create(Order $order, Event $event, array $tickets): self
    {
        $rows = [];
        $scanned = 0;
        foreach ($tickets as $ticket) {
            if ($ticket->status === TicketStatus::CHECKED_IN) {
                ++$scanned;
            }
            $rows[] = [
                'code' => $ticket->ticketCode,
                'typeName' => $ticket->ticketName,
                'status' => $ticket->status->value,
                'checkedInTime' => $ticket->checkedInAt !== null
                    ? VenueTime::toLocal($ticket->checkedInAt)->format('H:i')
                    : null,
                'scannable' => $ticket->status === TicketStatus::ISSUED
                    && $ticket->event->id->equals($event->id),
            ];
        }

        $date = new \IntlDateFormatter('nl_NL', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, VenueTime::TIMEZONE, null, 'd MMMM y HH:mm');

        return new self(
            orderNumber: $order->orderNumber,
            customerName: trim($order->customer->firstName . ' ' . $order->customer->lastName),
            eventDate: (string) $date->format(VenueTime::toLocal($event->startsAt)),
            total: count($rows),
            scanned: $scanned,
            tickets: $rows,
        );
    }
}
