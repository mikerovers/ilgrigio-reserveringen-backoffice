<?php

namespace App\Webhook;

use App\Service\WooCommerceService;
use Psr\Log\LoggerInterface;

/**
 * Normalizes the two WooCommerce webhook payload formats into a full order array.
 *
 * Shared by the HTTP request parser (App\Webhook\WooCommerceRequestParser) and the
 * queue-based ingest handler (App\MessageHandler\IncomingWooCommerceWebhookMessageHandler)
 * so both ingress routes produce an identical order array for downstream processing.
 */
class WooCommerceOrderNormalizer
{
    public function __construct(
        private WooCommerceService $wooCommerceService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Turn decoded webhook data into a full WooCommerce order array.
     *
     * Handles both the action-based format ({ "action": ..., "arg": orderId }), which
     * requires fetching the full order from the REST API, and the legacy format where the
     * order data is already present in the payload.
     *
     * @param array $webhookData Decoded JSON webhook payload
     * @param string $topic       Value of the X-WC-Webhook-Topic header
     * @param ?string $action     Value of the X-WC-Webhook-Event header, if available
     *
     * @throws WooCommerceOrderNormalizationException When the order cannot be resolved
     */
    public function normalize(array $webhookData, string $topic, ?string $action = null): array
    {
        // Action-based webhook (new format): fetch the full order from WooCommerce
        if (isset($webhookData['action'], $webhookData['arg'])) {
            $this->logger->info('Processing action-based webhook', [
                'action' => $webhookData['action'],
                'order_id' => $webhookData['arg'],
            ]);

            $orderId = (int) $webhookData['arg'];
            $orderData = $this->wooCommerceService->getOrder($orderId);

            if (!$orderData) {
                $this->logger->error('Failed to fetch order from WooCommerce', [
                    'order_id' => $orderId,
                ]);

                throw new WooCommerceOrderNormalizationException(
                    'Unable to fetch order data',
                    WooCommerceOrderNormalizationException::REASON_FETCH_FAILED
                );
            }

            $orderData['_webhook_topic'] = $topic;
            $orderData['_webhook_action'] = $webhookData['action'];

            return $orderData;
        }

        // Legacy format: order data is directly in the payload
        $orderData = $webhookData;

        if (!isset($orderData['id'])) {
            $this->logger->error('Missing order ID in webhook payload');

            throw new WooCommerceOrderNormalizationException(
                'Missing order ID in payload',
                WooCommerceOrderNormalizationException::REASON_MISSING_ID
            );
        }

        $orderData['_webhook_topic'] = $topic;

        if ($action !== null) {
            $orderData['_webhook_action'] = $action;
        }

        return $orderData;
    }
}
