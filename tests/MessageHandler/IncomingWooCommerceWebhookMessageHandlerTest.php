<?php

namespace App\Tests\MessageHandler;

use App\Message\IncomingWooCommerceWebhookMessage;
use App\MessageHandler\IncomingWooCommerceWebhookMessageHandler;
use App\Service\OrderPdfService;
use App\Service\WebhookSecurityService;
use App\Service\WooCommerceService;
use App\Webhook\WooCommerceOrderNormalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class IncomingWooCommerceWebhookMessageHandlerTest extends TestCase
{
    private MockObject|WebhookSecurityService $webhookSecurityService;
    private MockObject|WooCommerceService $wooCommerceService;
    private MockObject|OrderPdfService $orderPdfService;
    private MockObject|LoggerInterface $logger;
    private IncomingWooCommerceWebhookMessageHandler $handler;

    protected function setUp(): void
    {
        $this->webhookSecurityService = $this->createMock(WebhookSecurityService::class);
        $this->wooCommerceService = $this->createMock(WooCommerceService::class);
        $this->orderPdfService = $this->createMock(OrderPdfService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Use a real normalizer built from mocked collaborators (mirrors the parser test).
        $normalizer = new WooCommerceOrderNormalizer(
            $this->wooCommerceService,
            $this->logger
        );

        $this->handler = new IncomingWooCommerceWebhookMessageHandler(
            $this->webhookSecurityService,
            $normalizer,
            $this->orderPdfService,
            $this->logger
        );
    }

    public function testProcessesCompletedOrder(): void
    {
        $payload = json_encode(['id' => 123, 'status' => 'completed']);
        $message = new IncomingWooCommerceWebhookMessage($payload, 'good-sig', 'order.updated');

        $this->webhookSecurityService
            ->method('validateWooCommerceSignature')
            ->with($payload, 'good-sig')
            ->willReturn(true);
        $this->webhookSecurityService
            ->method('isValidWooCommerceOrder')
            ->willReturn(true);

        $this->orderPdfService
            ->expects($this->once())
            ->method('processOrder')
            ->with($this->callback(fn ($o) => $o['id'] === 123));

        ($this->handler)($message);
    }

    public function testRejectsInvalidSignature(): void
    {
        $message = new IncomingWooCommerceWebhookMessage('{"id":1}', 'bad-sig', 'order.updated');

        $this->webhookSecurityService
            ->method('validateWooCommerceSignature')
            ->willReturn(false);

        $this->orderPdfService->expects($this->never())->method('processOrder');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($this->handler)($message);
    }

    public function testRejectsMissingSignature(): void
    {
        $message = new IncomingWooCommerceWebhookMessage('{"id":1}', null, 'order.updated');

        $this->orderPdfService->expects($this->never())->method('processOrder');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($this->handler)($message);
    }

    public function testSkipsNonCompletedOrder(): void
    {
        $payload = json_encode(['id' => 123, 'status' => 'processing']);
        $message = new IncomingWooCommerceWebhookMessage($payload, 'good-sig', 'order.updated');

        $this->webhookSecurityService
            ->method('validateWooCommerceSignature')
            ->willReturn(true);
        $this->webhookSecurityService
            ->method('isValidWooCommerceOrder')
            ->willReturn(false);

        $this->orderPdfService->expects($this->never())->method('processOrder');

        ($this->handler)($message);
    }

    public function testSkipsNonOrderActionWebhook(): void
    {
        // A non-order action (e.g. a ticket hook) is acknowledged, not retried or
        // dead-lettered, and never reaches the order pipeline or the REST API.
        $payload = json_encode(['action' => 'il_grigio_ticket_created', 'arg' => 56074]);
        $message = new IncomingWooCommerceWebhookMessage(
            $payload,
            'good-sig',
            'action.il_grigio_ticket_created',
            'il_grigio_ticket_created'
        );

        $this->webhookSecurityService
            ->method('validateWooCommerceSignature')
            ->willReturn(true);

        $this->wooCommerceService->expects($this->never())->method('getOrder');
        $this->orderPdfService->expects($this->never())->method('processOrder');

        // No exception => the message is acknowledged (no retry / DLQ).
        ($this->handler)($message);
    }

    public function testInvalidJsonIsUnrecoverable(): void
    {
        $message = new IncomingWooCommerceWebhookMessage('not-json', 'good-sig', 'order.updated');

        $this->webhookSecurityService
            ->method('validateWooCommerceSignature')
            ->willReturn(true);

        $this->orderPdfService->expects($this->never())->method('processOrder');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        ($this->handler)($message);
    }
}
