<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Event;
use App\Entity\SharedStock;
use App\Entity\TicketType;
use App\Enum\StockStatus;
use Money\Money;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testDisplayFallsBackToAggregateWithoutDisplayStock(): void
    {
        $event = $this->eventWithPools(
            $this->pool(quantity: 3, status: StockStatus::IN_STOCK),
            $this->pool(quantity: 50, status: StockStatus::IN_STOCK),
        );

        self::assertSame(StockStatus::IN_STOCK, $event->displayStockStatus());
        self::assertSame(3, $event->displayStockQuantity());
    }

    public function testDisplayUsesChosenStockEvenWhenAnotherPoolIsLower(): void
    {
        $tickets = $this->pool(quantity: 2, status: StockStatus::IN_STOCK);
        $event = $this->eventWithPools(
            $tickets,
            $this->pool(quantity: 50, status: StockStatus::IN_STOCK),
        );
        $event->displayStock = $event->sharedStocks()[1];

        self::assertSame(50, $event->displayStockQuantity());
        self::assertSame(StockStatus::IN_STOCK, $event->displayStockStatus());
    }

    public function testDisplayShowsOutOfStockOfChosenStockOnly(): void
    {
        $event = $this->eventWithPools(
            $this->pool(quantity: 0, status: StockStatus::OUT_OF_STOCK),
            $this->pool(quantity: 40, status: StockStatus::IN_STOCK),
        );
        $event->displayStock = $event->sharedStocks()[0];

        self::assertSame(StockStatus::OUT_OF_STOCK, $event->displayStockStatus());
        self::assertSame(0, $event->displayStockQuantity());
        // Sales gating still considers every pool.
        self::assertSame(StockStatus::IN_STOCK, $event->stockStatus());
    }

    public function testDisplayQuantityIsNullForUnlimitedChosenStock(): void
    {
        $event = $this->eventWithPools(
            $this->pool(quantity: 5, status: StockStatus::IN_STOCK),
            $this->pool(quantity: null, status: StockStatus::IN_STOCK),
        );
        $event->displayStock = $event->sharedStocks()[1];

        self::assertNull($event->displayStockQuantity());
    }

    private function pool(?int $quantity, StockStatus $status): SharedStock
    {
        $pool = new SharedStock('Pool');
        $pool->stockQuantity = $quantity;
        $pool->stockStatus = $status;

        return $pool;
    }

    private function eventWithPools(SharedStock ...$pools): Event
    {
        $event = new Event('Show', new \DateTimeImmutable('+1 day'));
        foreach ($pools as $pool) {
            $ticketType = new TicketType($event, 'Ticket', Money::EUR(1000));
            $ticketType->sharedStock = $pool;
            $event->ticketTypes->add($ticketType);
        }

        return $event;
    }
}
