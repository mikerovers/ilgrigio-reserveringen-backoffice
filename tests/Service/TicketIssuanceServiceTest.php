<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\TicketType;
use App\Service\TicketCodeGenerator;
use App\Service\TicketIssuanceService;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TicketIssuanceServiceTest extends TestCase
{
    public function testIssuedTicketSnapshotsTheDoorsOpenMoment(): void
    {
        $event = new Event('Show', new \DateTimeImmutable('2026-10-19T20:00:00+00:00'));
        $event->doorsOpenAt = new \DateTimeImmutable('2026-10-19T19:00:00+00:00');

        $service = new TicketIssuanceService(
            $this->createMock(EntityManagerInterface::class),
            new TicketCodeGenerator(),
            new NullLogger(),
        );

        $tickets = $service->issueForOrder($this->orderFor($event), new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));

        self::assertCount(1, $tickets);
        self::assertEquals($event->doorsOpenAt, $tickets[0]->eventDoorsOpenSnapshot);
    }

    public function testIssuedTicketHasNoDoorsOpenSnapshotWhenTheEventHasNone(): void
    {
        $event = new Event('Show', new \DateTimeImmutable('2026-10-19T20:00:00+00:00'));

        $service = new TicketIssuanceService(
            $this->createMock(EntityManagerInterface::class),
            new TicketCodeGenerator(),
            new NullLogger(),
        );

        $tickets = $service->issueForOrder($this->orderFor($event), new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));

        self::assertCount(1, $tickets);
        self::assertNull($tickets[0]->eventDoorsOpenSnapshot);
    }

    private function orderFor(Event $event): Order
    {
        $order = new Order('ORD-1', new Customer('a@b.nl', 'A', 'B'), 900);
        $ticketType = new TicketType($event, 'Regular', Money::EUR(1000));
        $line = new OrderLineItem(
            $order,
            $ticketType,
            'Regular',
            1,
            Money::EUR(1000),
            Money::EUR(1000),
            Money::EUR(1000),
            Money::EUR(1000),
            Money::EUR(1000),
        );
        $order->lineItems->add($line);

        return $order;
    }
}
