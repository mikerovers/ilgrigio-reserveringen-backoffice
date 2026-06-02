<?php

namespace App\Messenger;

use App\Message\IncomingWooCommerceWebhookMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decodes raw WooCommerce webhooks delivered onto the ingest SQS queue by API Gateway.
 *
 * API Gateway's SQS integration writes the raw request body as the SQS message body and
 * forwards the relevant WooCommerce headers as String message attributes. The AmazonSqs
 * transport surfaces those as $encodedEnvelope['body'] and $encodedEnvelope['headers'],
 * which this serializer wraps into an IncomingWooCommerceWebhookMessage envelope.
 *
 * Re-queued messages (Messenger retries and dead-letter routing) are NOT raw API Gateway
 * payloads — they are full Messenger envelopes this serializer previously encoded. Those
 * must round-trip with their stamps intact (notably RedeliveryStamp, which counts retries
 * so exhausted messages reach the DLQ instead of looping forever). To get that for free we
 * delegate encode()/re-decode to the standard PhpSerializer and only apply the custom
 * mapping to the first, stamp-less API Gateway delivery.
 *
 * Header lookups are case-insensitive because API Gateway mapping templates and SQS
 * attribute names do not guarantee a canonical casing.
 */
class WooCommerceIngestSerializer implements SerializerInterface
{
    public function __construct(
        private PhpSerializer $inner = new PhpSerializer()
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        // A message we previously encoded is a PHP-serialized Envelope (with stamps).
        // Try the standard serializer first; if it cannot decode it, this is a raw
        // API Gateway webhook and we build the message ourselves.
        try {
            return $this->inner->decode($encodedEnvelope);
        } catch (MessageDecodingFailedException) {
            // Fall through to the raw WooCommerce webhook mapping below.
        }

        $payload = $encodedEnvelope['body'] ?? null;

        if (!is_string($payload) || $payload === '') {
            throw new MessageDecodingFailedException('Ingested WooCommerce webhook has an empty body');
        }

        $headers = $encodedEnvelope['headers'] ?? [];
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower((string) $name)] = $value;
        }

        $topic = $normalizedHeaders['x-wc-webhook-topic'] ?? null;

        if (!is_string($topic) || $topic === '') {
            throw new MessageDecodingFailedException(
                'Ingested WooCommerce webhook is missing the X-WC-Webhook-Topic attribute'
            );
        }

        $message = new IncomingWooCommerceWebhookMessage(
            $payload,
            $normalizedHeaders['x-wc-webhook-signature'] ?? null,
            $topic,
            $normalizedHeaders['x-wc-webhook-event'] ?? null
        );

        return new Envelope($message);
    }

    public function encode(Envelope $envelope): array
    {
        // Delegate so retries/dead-lettering re-queue a full envelope with stamps intact.
        return $this->inner->encode($envelope);
    }
}
