<?php

namespace App\Tests\Webhook;

use App\Controller\WooCommerceWebhookController;
use App\Service\OrderPdfService;
use App\Service\WebhookSecurityService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RemoteEvent\RemoteEvent;

class WooCommerceWebhookControllerTest extends KernelTestCase
{
    private WooCommerceWebhookController $controller;
    private MockObject|OrderPdfService $orderPdfService;
    private MockObject|WebhookSecurityService $webhookSecurityService;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->orderPdfService = $this->createMock(OrderPdfService::class);
        $this->webhookSecurityService = $this->createMock(WebhookSecurityService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new WooCommerceWebhookController(
            $this->orderPdfService,
            $this->webhookSecurityService,
            $this->logger
        );
    }

    public function testConsumeValidOrder(): void
    {
        $orderData = [
            'id' => 123,
            'status' => 'completed',
            'billing' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com'
            ]
        ];

        $event = new RemoteEvent('woocommerce', '123', $orderData);

        $this->webhookSecurityService
            ->expects($this->once())
            ->method('isValidWooCommerceOrder')
            ->with($orderData)
            ->willReturn(true);

        $this->orderPdfService
            ->expects($this->once())
            ->method('processOrder')
            ->with($orderData);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('WooCommerce order webhook received', [
                'order_id' => 123,
                'status' => 'completed',
                'topic' => 'unknown'
            ]);

        $this->controller->consume($event);
    }

    public function testConsumeInvalidOrder(): void
    {
        $orderData = [
            'id' => 456,
            'status' => 'draft'
        ];

        $event = new RemoteEvent('woocommerce', '456', $orderData);

        $this->webhookSecurityService
            ->expects($this->once())
            ->method('isValidWooCommerceOrder')
            ->with($orderData)
            ->willReturn(false);

        $this->orderPdfService
            ->expects($this->never())
            ->method('processOrder');

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('Skipping invalid, draft, or failed order', [
                'order_id' => 456,
                'status' => 'draft'
            ]);

        $this->controller->consume($event);
    }
}
