<?php

namespace App\Tests\Webhook;

use App\Service\WebhookSecurityService;
use App\Webhook\WooCommerceRequestParser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

class WooCommerceRequestParserTest extends KernelTestCase
{
    private WooCommerceRequestParser $parser;
    private MockObject|WebhookSecurityService $webhookSecurityService;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->webhookSecurityService = $this->createMock(WebhookSecurityService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->parser = new WooCommerceRequestParser(
            $this->webhookSecurityService,
            $this->logger
        );
    }

    public function testParseValidWebhook(): void
    {
        $orderData = [
        'id' => 123,
        'status' => 'processing',
        'billing' => [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com'
        ]
        ];

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'valid-signature'
            ],
            json_encode($orderData)
        );

        $secret = 'test-secret';

        $this->webhookSecurityService
        ->expects($this->once())
        ->method('validateWooCommerceSignature')
        ->with(json_encode($orderData), 'valid-signature', $secret)
        ->willReturn(true);

        $event = $this->parser->parse($request, $secret);

        $this->assertInstanceOf(RemoteEvent::class, $event);
        $this->assertEquals('woocommerce', $event->getName());
        $this->assertEquals('123', $event->getId());

        $payload = $event->getPayload();
        $this->assertEquals('order.created', $payload['_webhook_topic']);
        unset($payload['_webhook_topic']);
        $this->assertEquals($orderData, $payload);
    }

    public function testParseInvalidJson(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            'invalid-json'
        );

        $this->logger
        ->expects($this->once())
        ->method('error')
        ->with('Invalid webhook payload received');

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Invalid JSON payload');

        $this->parser->parse($request, '');
    }

    public function testParseInvalidSignature(): void
    {
        $orderData = ['id' => 123, 'status' => 'processing'];
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'invalid-signature'
            ],
            json_encode($orderData)
        );

        $secret = 'test-secret';

        $this->webhookSecurityService
        ->expects($this->once())
        ->method('validateWooCommerceSignature')
        ->with(json_encode($orderData), 'invalid-signature', $secret)
        ->willReturn(false);

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Invalid signature');

        $this->parser->parse($request, $secret);
    }

    public function testParseMissingSignature(): void
    {
        $orderData = ['id' => 123, 'status' => 'processing'];
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST'],
            json_encode($orderData)
        );

        $secret = 'test-secret';

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Missing signature header');

        $this->parser->parse($request, $secret);
    }

    public function testParseWithoutSecret(): void
    {
        $orderData = [
        'id' => 123,
        'status' => 'processing'
        ];

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.updated'
            ],
            json_encode($orderData)
        );

        $event = $this->parser->parse($request, '');

        $this->assertInstanceOf(RemoteEvent::class, $event);
        $this->assertEquals('woocommerce', $event->getName());
        $this->assertEquals('123', $event->getId());

        $payload = $event->getPayload();
        $this->assertEquals('order.updated', $payload['_webhook_topic']);
        unset($payload['_webhook_topic']);
        $this->assertEquals($orderData, $payload);
    }
}
