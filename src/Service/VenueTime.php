<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Times are stored and exchanged in UTC; the venue (and its audience) lives in
 * Amsterdam time. Use this wherever a stored instant is shown as a local
 * clock time, or a locally typed clock time is turned into a stored instant.
 */
final class VenueTime
{
    public const TIMEZONE = 'Europe/Amsterdam';
    public const STORAGE_TIMEZONE = 'UTC';

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /** The stored instant as Amsterdam wall-clock time (for display). */
    public static function toLocal(\DateTimeInterface $instant): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone());
    }

    /** An Amsterdam wall-clock time (e.g. "2027-07-01 14:00") as a UTC instant, ready to store. */
    public static function fromLocal(string $localDateTime): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($localDateTime, self::zone()))
            ->setTimezone(new \DateTimeZone(self::STORAGE_TIMEZONE));
    }
}
