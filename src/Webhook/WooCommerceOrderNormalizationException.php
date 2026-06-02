<?php

namespace App\Webhook;

/**
 * Thrown when a WooCommerce webhook payload cannot be normalized into a full order.
 *
 * The reason code lets callers translate the failure into the appropriate response
 * for their ingress route (HTTP RejectWebhookException vs. re-throw for queue retry).
 */
class WooCommerceOrderNormalizationException extends \RuntimeException
{
    public const REASON_MISSING_ID = 'missing_id';
    public const REASON_FETCH_FAILED = 'fetch_failed';

    public function __construct(
        string $message,
        private string $reason
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
