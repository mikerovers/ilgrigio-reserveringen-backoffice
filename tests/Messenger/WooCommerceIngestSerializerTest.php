<?php

namespace App\Tests\Messenger;

use App\Message\IncomingWooCommerceWebhookMessage;
use App\Messenger\WooCommerceIngestSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

class WooCommerceIngestSerializerTest extends TestCase
{
    private WooCommerceIngestSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new WooCommerceIngestSerializer();
    }

    public function testDecodeWrapsBodyAndHeaders(): void
    {
        $body = json_encode(['id' => 123, 'status' => 'completed']);

        $envelope = $this->serializer->decode([
            'body' => $body,
            'headers' => [
                'X-WC-Webhook-Signature' => 'sig-value',
                'X-WC-Webhook-Topic' => 'order.updated',
                'X-WC-Webhook-Event' => 'order.updated',
            ],
        ]);

        $message = $envelope->getMessage();
        $this->assertInstanceOf(IncomingWooCommerceWebhookMessage::class, $message);
        $this->assertSame($body, $message->getPayload());
        $this->assertSame('sig-value', $message->getSignature());
        $this->assertSame('order.updated', $message->getTopic());
        $this->assertSame('order.updated', $message->getEvent());
    }

    public function testDecodeIsCaseInsensitiveForHeaders(): void
    {
        $envelope = $this->serializer->decode([
            'body' => '{"id":1}',
            'headers' => [
                'x-wc-webhook-signature' => 'sig',
                'x-wc-webhook-topic' => 'order.created',
            ],
        ]);

        $message = $envelope->getMessage();
        $this->assertSame('sig', $message->getSignature());
        $this->assertSame('order.created', $message->getTopic());
        $this->assertNull($message->getEvent());
    }

    public function testDecodeThrowsOnEmptyBody(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode([
            'body' => '',
            'headers' => ['X-WC-Webhook-Topic' => 'order.updated'],
        ]);
    }

    public function testDecodeThrowsWhenTopicMissing(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode([
            'body' => '{"id":1}',
            'headers' => ['X-WC-Webhook-Signature' => 'sig'],
        ]);
    }

    public function testEncodeDecodeRoundTripPreservesMessageAndStamps(): void
    {
        $original = new \Symfony\Component\Messenger\Envelope(
            new IncomingWooCommerceWebhookMessage(
                '{"action":"woocommerce_order_status_completed","arg":94982}',
                'sig123',
                'action.woocommerce_order_status_completed',
                'order.updated'
            ),
            [new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(2)]
        );

        // Re-queued messages (retries / DLQ) must round-trip with their retry count
        // intact, otherwise an always-failing message would loop forever.
        $encoded = $this->serializer->encode($original);
        $decoded = $this->serializer->decode($encoded);

        $message = $decoded->getMessage();
        $this->assertInstanceOf(IncomingWooCommerceWebhookMessage::class, $message);
        $this->assertSame('sig123', $message->getSignature());
        $this->assertSame('action.woocommerce_order_status_completed', $message->getTopic());

        $stamp = $decoded->last(\Symfony\Component\Messenger\Stamp\RedeliveryStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(2, $stamp->getRetryCount());
    }
}
