<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\CheckInBroadcaster;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class CheckInBroadcasterTest extends TestCase
{
    private HubInterface&MockObject $hub;
    private LoggerInterface&MockObject $logger;
    private Event $event;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->event = new Event('Biblio Magico!', new \DateTimeImmutable('2027-07-01 12:00:00'));
    }

    public function testPublishesAPrivateUpdateOnTheEventTopic(): void
    {
        $this->hub->expects(self::once())->method('publish')->with(self::callback(function (Update $update): bool {
            $payload = json_decode($update->getData(), true);

            return $update->isPrivate()
                && $update->getTopics() === ['scanner/events/' . $this->event->id->toRfc4122()]
                && $payload['stats'] === ['expected' => 148, 'checkedIn' => 63, 'remaining' => 85]
                && $payload['scanId'] === 'abc';
        }));

        (new CheckInBroadcaster($this->hub, $this->logger, true))->admitted(
            $this->event,
            ['issued' => 85, 'checkedIn' => 63, 'void' => 3],
            'abc',
            'balie@example.com',
            new \DateTimeImmutable('2027-07-01 13:47:00'),
        );
    }

    public function testDoesNothingWhenDisabled(): void
    {
        $this->hub->expects(self::never())->method('publish');

        (new CheckInBroadcaster($this->hub, $this->logger, false))->admitted(
            $this->event,
            ['issued' => 1, 'checkedIn' => 1, 'void' => 0],
            null,
            null,
            new \DateTimeImmutable(),
        );
    }

    public function testAHubOutageIsLoggedAndNeverThrown(): void
    {
        $this->hub->method('publish')->willThrowException(new \RuntimeException('hub down'));
        $this->logger->expects(self::once())->method('error');

        (new CheckInBroadcaster($this->hub, $this->logger, true))->admitted(
            $this->event,
            ['issued' => 1, 'checkedIn' => 1, 'void' => 0],
            null,
            null,
            new \DateTimeImmutable(),
        );

        $this->addToAssertionCount(1);
    }
}
