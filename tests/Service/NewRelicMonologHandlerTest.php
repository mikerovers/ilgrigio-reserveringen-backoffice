<?php

namespace App\Tests\Service;

use App\Service\NewRelicMonologHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class NewRelicMonologHandlerTest extends TestCase
{
    private HttpClientInterface|MockObject $httpClient;
    private LoggerInterface|MockObject $fallbackLogger;
    private NewRelicMonologHandler $handler;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->fallbackLogger = $this->createMock(LoggerInterface::class);

        $this->handler = new NewRelicMonologHandler(
            httpClient: $this->httpClient,
            fallbackLogger: $this->fallbackLogger,
            licenseKey: 'test_license_key',
            endpoint: 'https://log-api.newrelic.com',
            appName: 'test-app',
            environment: 'test'
        );
    }

    public function testHandleLogsSuccessfully(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('headers', $options);
                    $this->assertArrayHasKey('json', $options);
                    $this->assertArrayHasKey('timeout', $options);
                    $this->assertEquals('application/json', $options['headers']['Content-Type']);
                    $this->assertEquals('test_license_key', $options['headers']['Api-Key']);
                    $this->assertEquals(5, $options['timeout']);

                    $payload = $options['json'];
                    $this->assertIsArray($payload);
                    $this->assertArrayHasKey(0, $payload);
                    $this->assertArrayHasKey('logs', $payload[0]);
                    $this->assertIsArray($payload[0]['logs']);
                    $this->assertCount(1, $payload[0]['logs']);

                    $logEntry = $payload[0]['logs'][0];
                    $this->assertArrayHasKey('message', $logEntry);
                    $this->assertArrayHasKey('level', $logEntry);
                    $this->assertArrayHasKey('app.name', $logEntry);
                    $this->assertArrayHasKey('environment', $logEntry);
                    $this->assertEquals('test-app', $logEntry['app.name']);
                    $this->assertEquals('test', $logEntry['environment']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->fallbackLogger
            ->expects($this->never())
            ->method('error');

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test log message',
            context: [],
            extra: []
        );

        $this->handler->handle($record);
    }

    public function testHandleLogsWithContext(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->callback(function ($options) {
                    $logEntry = $options['json'][0]['logs'][0];
                    $this->assertArrayHasKey('context.user_id', $logEntry);
                    $this->assertArrayHasKey('context.order_id', $logEntry);
                    $this->assertEquals(123, $logEntry['context.user_id']);
                    $this->assertEquals(456, $logEntry['context.order_id']);

                    return true;
                })
            )
            ->willReturn($response);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'Test with context',
            context: [
                'user_id' => 123,
                'order_id' => 456,
            ],
            extra: []
        );

        $this->handler->handle($record);
    }

    public function testHandleLogsWithExtraData(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->callback(function ($options) {
                    $logEntry = $options['json'][0]['logs'][0];
                    $this->assertArrayHasKey('extra.file', $logEntry);
                    $this->assertArrayHasKey('extra.line', $logEntry);

                    return true;
                })
            )
            ->willReturn($response);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Test with extra data',
            context: [],
            extra: [
                'file' => '/path/to/file.php',
                'line' => 42,
            ]
        );

        $this->handler->handle($record);
    }

    public function testHandleFallsBackOnHttpClientException(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Network error'));

        $this->fallbackLogger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Failed to send log to New Relic',
                $this->callback(function ($context) {
                    $this->assertArrayHasKey('error', $context);
                    $this->assertArrayHasKey('original_message', $context);
                    $this->assertEquals('Network error', $context['error']);

                    return true;
                })
            );

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test log message',
            context: [],
            extra: []
        );

        $this->handler->handle($record);
    }

    public function testHandleTrimsEndpointSlash(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $handler = new NewRelicMonologHandler(
            httpClient: $this->httpClient,
            fallbackLogger: $this->fallbackLogger,
            licenseKey: 'test_license_key',
            endpoint: 'https://log-api.newrelic.com/',
            appName: 'test-app',
            environment: 'test'
        );

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->anything()
            )
            ->willReturn($response);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test log message',
            context: [],
            extra: []
        );

        $handler->handle($record);
    }

    public function testHandleSanitizesComplexValues(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->callback(function ($options) {
                    $logEntry = $options['json'][0]['logs'][0];
                    $this->assertArrayHasKey('context.array_data', $logEntry);
                    $this->assertIsString($logEntry['context.array_data']);
                    $this->assertStringContainsString('test', $logEntry['context.array_data']);

                    return true;
                })
            )
            ->willReturn($response);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test with complex data',
            context: [
                'array_data' => ['key' => 'test', 'nested' => ['value' => 123]],
            ],
            extra: []
        );

        $this->handler->handle($record);
    }

    public function testHandleInterpolatesPsr3Placeholders(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->callback(function ($options) {
                    $logEntry = $options['json'][0]['logs'][0];
                    // Verify the message has placeholders replaced
                    $this->assertEquals(
                        'Worker stopped due to time limit of 60s exceeded',
                        $logEntry['message']
                    );
                    // Context should still be present as separate fields
                    $this->assertArrayHasKey('context.timeLimit', $logEntry);
                    $this->assertEquals(60, $logEntry['context.timeLimit']);

                    return true;
                })
            )
            ->willReturn($response);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'messenger',
            level: Level::Info,
            message: 'Worker stopped due to time limit of {timeLimit}s exceeded',
            context: [
                'timeLimit' => 60,
            ],
            extra: []
        );

        $this->handler->handle($record);
    }

    public function testHandleInterpolatesMultiplePlaceholders(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://log-api.newrelic.com/log/v1',
                $this->callback(function ($options) {
                    $logEntry = $options['json'][0]['logs'][0];
                    $this->assertEquals(
                        'User john processed order 12345',
                        $logEntry['message']
                    );

                    return true;
                })
            )
            ->willReturn($response);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'User {username} processed order {orderId}',
            context: [
                'username' => 'john',
                'orderId' => 12345,
            ],
            extra: []
        );

        $this->handler->handle($record);
    }
}
