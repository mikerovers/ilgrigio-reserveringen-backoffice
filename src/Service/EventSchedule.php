<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Formats the schedule of an event for display: the event date, the start time
 * and the doors-open moment. Everything is rendered in venue (Amsterdam) time.
 */
final class EventSchedule
{
    /** The calendar day of an instant, in venue time, e.g. 2026-10-19. */
    public static function day(\DateTimeImmutable $instant): string
    {
        return VenueTime::toLocal($instant)->format('Y-m-d');
    }

    /** The date of an instant, in venue time, e.g. 19-10-2026. */
    public static function date(\DateTimeImmutable $instant): string
    {
        return VenueTime::toLocal($instant)->format('d-m-Y');
    }

    /** The time of an instant, in venue time, e.g. 20:00. */
    public static function time(\DateTimeImmutable $instant): string
    {
        return VenueTime::toLocal($instant)->format('H:i');
    }

    /** Whether two instants fall on the same calendar day in venue time. */
    public static function isSameDay(\DateTimeImmutable $a, \DateTimeImmutable $b): bool
    {
        return self::day($a) === self::day($b);
    }

    /**
     * The doors-open moment as shown to visitors: the time only when the doors
     * open on the same day as the show starts, the date and the time otherwise.
     */
    public static function doorsOpen(?\DateTimeImmutable $doorsOpenAt, \DateTimeImmutable $startsAt): ?string
    {
        if ($doorsOpenAt === null) {
            return null;
        }

        if (self::isSameDay($doorsOpenAt, $startsAt)) {
            return self::time($doorsOpenAt);
        }

        return self::date($doorsOpenAt) . ' ' . self::time($doorsOpenAt);
    }
}
