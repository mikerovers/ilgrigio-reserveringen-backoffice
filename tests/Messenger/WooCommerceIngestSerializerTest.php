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

    public function testEncodeIsUnsupported(): void
    {
        $this->expectException(\LogicException::class);

        $this->serializer->encode(
            new \Symfony\Component\Messenger\Envelope(
                new IncomingWooCommerceWebhookMessage('{}', null, 'order.updated')
            )
        );
    }
}
