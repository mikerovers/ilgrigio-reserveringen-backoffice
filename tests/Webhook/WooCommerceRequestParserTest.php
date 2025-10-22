<?php

namespace App\Tests\Webhook;

use App\Service\WebhookSecurityService;
use App\Service\WooCommerceService;
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
    private MockObject|WooCommerceService $wooCommerceService;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->webhookSecurityService = $this->createMock(WebhookSecurityService::class);
        $this->wooCommerceService = $this->createMock(WooCommerceService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->parser = new WooCommerceRequestParser(
            $this->webhookSecurityService,
            $this->wooCommerceService,
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

    public function testParseActionBasedWebhook(): void
    {
        $actionData = [
            'action' => 'woocommerce_order_status_completed',
            'arg' => 94870
        ];

        $fullOrderData = [
            'id' => 94870,
            'status' => 'completed',
            'billing' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com'
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
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'action.woocommerce_order_status_completed',
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'valid-signature'
            ],
            json_encode($actionData)
        );

        $secret = 'test-secret';

        $this->webhookSecurityService
            ->expects($this->once())
            ->method('validateWooCommerceSignature')
            ->with(json_encode($actionData), 'valid-signature', $secret)
            ->willReturn(true);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(94870)
            ->willReturn($fullOrderData);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Processing action-based webhook', [
                'action' => 'woocommerce_order_status_completed',
                'order_id' => 94870
            ]);

        $event = $this->parser->parse($request, $secret);

        $this->assertInstanceOf(RemoteEvent::class, $event);
        $this->assertEquals('woocommerce', $event->getName());
        $this->assertEquals('94870', $event->getId());

        $payload = $event->getPayload();
        $this->assertEquals('action.woocommerce_order_status_completed', $payload['_webhook_topic']);
        $this->assertEquals('woocommerce_order_status_completed', $payload['_webhook_action']);
        $this->assertEquals($fullOrderData['id'], $payload['id']);
        $this->assertEquals($fullOrderData['status'], $payload['status']);
    }

    public function testParseActionBasedWebhookFailedToFetchOrder(): void
    {
        $actionData = [
            'action' => 'woocommerce_order_status_completed',
            'arg' => 99999
        ];

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'action.woocommerce_order_status_completed',
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'valid-signature'
            ],
            json_encode($actionData)
        );

        $secret = 'test-secret';

        $this->webhookSecurityService
            ->expects($this->once())
            ->method('validateWooCommerceSignature')
            ->with(json_encode($actionData), 'valid-signature', $secret)
            ->willReturn(true);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(99999)
            ->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Processing action-based webhook', [
                'action' => 'woocommerce_order_status_completed',
                'order_id' => 99999
            ]);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Failed to fetch order from WooCommerce', [
                'order_id' => 99999
            ]);

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Unable to fetch order data');

        $this->parser->parse($request, $secret);
    }

    public function testParseMissingWebhookTopic(): void
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

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Missing X-WC-Webhook-Topic header');

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Missing webhook topic header');

        $this->parser->parse($request, $secret);
    }

    public function testParseMissingOrderIdInLegacyFormat(): void
    {
        $orderData = ['status' => 'processing'];
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

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Missing order ID in webhook payload');

        $this->expectException(RejectWebhookException::class);
        $this->expectExceptionMessage('Missing order ID in payload');

        $this->parser->parse($request, $secret);
    }
}
