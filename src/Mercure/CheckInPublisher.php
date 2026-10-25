<?php

declare(strict_types=1);

namespace App\Mercure;

use App\DTO\CheckInUpdate;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes check-in updates to the Mercure hub so all scanner clients
 * for an event see admissions in real time.
 *
 * Updates carry ticket codes, so they are published as private: only
 * subscribers holding a subscriber JWT for the event topic receive them.
 *
 * Publishing is best-effort: if the hub is unavailable or Mercure is
 * disabled, the check-in still succeeds and only a warning is logged.
 */
final class CheckInPublisher
{
    public function __construct(
        private ?HubInterface $hub,
        private LoggerInterface $logger,
        private bool $enabled,
    ) {
    }

    /**
     * Absolute topic (URN) so publisher and subscriber match literally, independent of the hub's public URL.
     */
    public static function topicFor(string $eventId): string
    {
        return sprintf('urn:ilgrigio:scanner:event:%s:check-ins', $eventId);
    }

    public function publishCheckIn(string $eventId, CheckInUpdate $update): void
    {
        if (!$this->enabled || $this->hub === null) {
            return;
        }

        try {
            $this->hub->publish(new Update(
                topics: self::topicFor($eventId),
                data: json_encode($update, JSON_THROW_ON_ERROR),
                private: true,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure check-in publish failed', [
                'eventId' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
