<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EventSchedule;
use PHPUnit\Framework\TestCase;

class EventScheduleTest extends TestCase
{
    public function testDoorsOpenOnTheSameDayShowsOnlyTheTime(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-19T20:00:00+00:00');
        $doorsOpenAt = new \DateTimeImmutable('2026-10-19T19:00:00+00:00');

        self::assertSame('21:00', EventSchedule::doorsOpen($doorsOpenAt, $startsAt));
    }

    public function testDoorsOpenOnAnotherDayShowsTheDateAndTime(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-19T20:00:00+00:00');
        $doorsOpenAt = new \DateTimeImmutable('2026-10-18T18:00:00+00:00');

        self::assertSame('18-10-2026 20:00', EventSchedule::doorsOpen($doorsOpenAt, $startsAt));
    }

    public function testDoorsOpenOnAnotherDayIsDetectedInVenueTime(): void
    {
        // 23:30 UTC is already the next day in Amsterdam (UTC+2 in October).
        $startsAt = new \DateTimeImmutable('2026-10-20T01:00:00+00:00');
        $doorsOpenAt = new \DateTimeImmutable('2026-10-19T23:30:00+00:00');

        self::assertTrue(EventSchedule::isSameDay($doorsOpenAt, $startsAt));
        self::assertSame('01:30', EventSchedule::doorsOpen($doorsOpenAt, $startsAt));
    }

    public function testWithoutADoorsOpenMomentNothingIsShown(): void
    {
        $startsAt = new \DateTimeImmutable('2026-10-19T20:00:00+00:00');

        self::assertNull(EventSchedule::doorsOpen(null, $startsAt));
    }

    public function testDateAndTimeAreRenderedInVenueTime(): void
    {
        $instant = new \DateTimeImmutable('2026-10-19T20:00:00+00:00');

        self::assertSame('19-10-2026', EventSchedule::date($instant));
        self::assertSame('22:00', EventSchedule::time($instant));
        self::assertSame('2026-10-19', EventSchedule::day($instant));
    }
}
