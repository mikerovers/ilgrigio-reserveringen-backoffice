<?php

namespace App\Messenger;

use App\Message\IncomingWooCommerceWebhookMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decodes raw WooCommerce webhooks delivered onto the ingest SQS queue by API Gateway.
 *
 * API Gateway's SQS integration writes the raw request body as the SQS message body and
 * forwards the relevant WooCommerce headers as String message attributes. The AmazonSqs
 * transport surfaces those as $encodedEnvelope['body'] and $encodedEnvelope['headers'],
 * which this serializer wraps into an IncomingWooCommerceWebhookMessage envelope.
 *
 * Header lookups are case-insensitive because API Gateway mapping templates and SQS
 * attribute names do not guarantee a canonical casing.
 *
 * This is a decode-only serializer: the ingest queue is never written to from PHP, so
 * encode() is unsupported.
 */
class WooCommerceIngestSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        $payload = $encodedEnvelope["body"] ?? null;

        if (!is_string($payload) || $payload === "") {
            throw new MessageDecodingFailedException(
                "Ingested WooCommerce webhook has an empty body",
            );
        }

        $headers = $encodedEnvelope["headers"] ?? [];
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower((string) $name)] = $value;
        }

        $topic = $normalizedHeaders["x-wc-webhook-topic"] ?? null;

        if (!is_string($topic) || $topic === "") {
            throw new MessageDecodingFailedException(
                "Ingested WooCommerce webhook is missing the X-WC-Webhook-Topic attribute",
            );
        }

        $message = new IncomingWooCommerceWebhookMessage(
            $payload,
            $normalizedHeaders["x-wc-webhook-signature"] ?? null,
            $topic,
            $normalizedHeaders["x-wc-webhook-event"] ?? null,
        );

        return new Envelope($message);
    }

    public function encode(Envelope $envelope): array
    {
        throw new \LogicException(
            "The WooCommerce ingest queue is read-only; messages cannot be encoded to it.",
        );
    }
}
