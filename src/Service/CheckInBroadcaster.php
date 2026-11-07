<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells every open scanner screen that a ticket was admitted, so all balies
 * show the same counters. Publishing is best effort: a hub outage must never
 * fail a door scan.
 */
final class CheckInBroadcaster
{
    /**
     * One topic for every show: a balie only acts on updates carrying its own
     * eventId. Mercure 1.0 publisher tokens can only name exact topics, so a
     * topic per show would need a new token grant for each of them.
     */
    public const TOPIC = 'https://reserveringen.ilgrigio.nl/scanner/check-ins';

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
        if (!$this->enabled) {
            return;
        }

        $payload = [
            'type' => 'checked_in',
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
