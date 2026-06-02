<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A raw WooCommerce webhook as delivered onto the ingest queue by API Gateway.
 *
 * Unlike SendOrderEmailMessage, this is never dispatched from PHP — it is materialized
 * by WooCommerceIngestSerializer from the raw SQS body + message attributes that API
 * Gateway forwards. The handler validates the signature and normalizes the payload
 * before handing off to the existing order pipeline.
 */
#[AsMessage('webhook_ingest')]
class IncomingWooCommerceWebhookMessage
{
    public function __construct(
        private string $payload,
        private ?string $signature,
        private string $topic,
        private ?string $event = null
    ) {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getEvent(): ?string
    {
        return $this->event;
    }
}
