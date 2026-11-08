<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells every open scanner screen that the counters of a show changed, so all
 * balies show the same numbers. Publishing is best effort: a hub outage must
 * never fail a door scan or a cancellation.
 */
final class CheckInBroadcaster
{
    /**
     * One topic for every show: a balie only acts on updates carrying its own
     * eventId. Mercure 1.0 publisher tokens can only name exact topics, so a
     * topic per show would need a new token grant for each of them.
     */
    public const TOPIC = 'https://reserveringen.ilgrigio.nl/scanner/check-ins';

    public const TYPE_CHECKED_IN = 'checked_in';
    public const TYPE_STATS_CHANGED = 'stats_changed';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
        private bool $enabled = false,
    ) {
    }

    /**
     * @param array{issued: int, checkedIn: int, void: int} $counts
     */
    public function admitted(Event $event, array $counts, ?string $scanId, ?string $by, \DateTimeImmutable $at): void
    {
        $this->publish(self::TYPE_CHECKED_IN, $event, $counts, $scanId, $by, $at);
    }

    /**
     * Counters changed without a door scan (e.g. tickets voided by a cancellation).
     *
     * @param array{issued: int, checkedIn: int, void: int} $counts
     */
    public function changed(Event $event, array $counts, \DateTimeImmutable $at): void
    {
        $this->publish(self::TYPE_STATS_CHANGED, $event, $counts, null, null, $at);
    }

    /**
     * @param array{issued: int, checkedIn: int, void: int} $counts
     */
    private function publish(
        string $type,
        Event $event,
        array $counts,
        ?string $scanId,
        ?string $by,
        \DateTimeImmutable $at,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $payload = [
            'type' => $type,
            'eventId' => $event->id->toRfc4122(),
            'scanId' => $scanId,
            'by' => $by,
            'at' => $at->format(\DateTimeInterface::ATOM),
            'stats' => [
                'expected' => $counts['issued'] + $counts['checkedIn'],
                'checkedIn' => $counts['checkedIn'],
                'remaining' => $counts['issued'],
            ],
        ];

        try {
            $this->hub->publish(new Update(self::TOPIC, json_encode($payload, JSON_THROW_ON_ERROR), private: true));
        } catch (\Throwable $e) {
            $this->logger->error('Could not publish check-in update to Mercure', [
                'event' => $event->id->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
