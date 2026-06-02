<?php

namespace App\Tests\Webhook;

use App\Service\WooCommerceService;
use App\Webhook\WooCommerceOrderNormalizationException;
use App\Webhook\WooCommerceOrderNormalizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WooCommerceOrderNormalizerTest extends TestCase
{
    private MockObject|WooCommerceService $wooCommerceService;
    private MockObject|LoggerInterface $logger;
    private WooCommerceOrderNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->wooCommerceService = $this->createMock(WooCommerceService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->normalizer = new WooCommerceOrderNormalizer(
            $this->wooCommerceService,
            $this->logger
        );
    }

    public function testNormalizeLegacyFormatAddsTopic(): void
    {
        $this->wooCommerceService->expects($this->never())->method('getOrder');

        $order = $this->normalizer->normalize(
            ['id' => 123, 'status' => 'completed'],
            'order.updated'
        );

        $this->assertSame(123, $order['id']);
        $this->assertSame('order.updated', $order['_webhook_topic']);
        $this->assertArrayNotHasKey('_webhook_action', $order);
    }

    public function testNormalizeLegacyFormatAddsEventWhenProvided(): void
    {
        $order = $this->normalizer->normalize(
            ['id' => 123, 'status' => 'completed'],
            'order.updated',
            'woocommerce_order_status_completed'
        );

        $this->assertSame('woocommerce_order_status_completed', $order['_webhook_action']);
    }

    public function testNormalizeActionFormatFetchesFullOrder(): void
    {
        $fullOrder = ['id' => 94870, 'status' => 'completed'];

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(94870)
            ->willReturn($fullOrder);

        $order = $this->normalizer->normalize(
            ['action' => 'woocommerce_order_status_completed', 'arg' => 94870],
            'action.woocommerce_order_status_completed'
        );

        $this->assertSame(94870, $order['id']);
        $this->assertSame('action.woocommerce_order_status_completed', $order['_webhook_topic']);
        $this->assertSame('woocommerce_order_status_completed', $order['_webhook_action']);
    }

    public function testNormalizeActionFormatThrowsWhenFetchFails(): void
    {
        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(99999)
            ->willReturn(null);

        try {
            $this->normalizer->normalize(
                ['action' => 'woocommerce_order_status_completed', 'arg' => 99999],
                'action.woocommerce_order_status_completed'
            );
            $this->fail('Expected WooCommerceOrderNormalizationException');
        } catch (WooCommerceOrderNormalizationException $e) {
            $this->assertSame(
                WooCommerceOrderNormalizationException::REASON_FETCH_FAILED,
                $e->getReason()
            );
        }
    }

    public function testNormalizeLegacyFormatThrowsWhenOrderIdMissing(): void
    {
        try {
            $this->normalizer->normalize(['status' => 'completed'], 'order.updated');
            $this->fail('Expected WooCommerceOrderNormalizationException');
        } catch (WooCommerceOrderNormalizationException $e) {
            $this->assertSame(
                WooCommerceOrderNormalizationException::REASON_MISSING_ID,
                $e->getReason()
            );
        }
    }
}
