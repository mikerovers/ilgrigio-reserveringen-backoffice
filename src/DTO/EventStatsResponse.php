<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Door counters of one event: expected = checkedIn + remaining (void tickets excluded).
 */
final class EventStatsResponse
{
    public function __construct(
        public int $expected,
        public int $checkedIn,
        public int $remaining,
    ) {
    }

    /**
     * @param array{issued: int, checkedIn: int, void: int} $counts
     */
    public static function fromCounts(array $counts): self
    {
        return new self(
            expected: $counts['issued'] + $counts['checkedIn'],
            checkedIn: $counts['checkedIn'],
            remaining: $counts['issued'],
        );
    }
}
