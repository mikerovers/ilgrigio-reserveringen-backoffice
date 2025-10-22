<?php

namespace App\Webhook;

use App\Service\WebhookSecurityService;
use App\Service\WooCommerceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

class WooCommerceRequestParser extends AbstractRequestParser
{
    public function __construct(
        private WebhookSecurityService $webhookSecurityService,
        private WooCommerceService $wooCommerceService,
        private LoggerInterface $logger
    ) {}

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new MethodRequestMatcher(['POST']);
    }

    protected function doParse(Request $request, string $secret): ?RemoteEvent
    {
        $payload = $request->getContent();
        $webhookData = json_decode($payload, true);

        if (!$webhookData) {
            $this->logger->error('Invalid webhook payload received');
            throw new RejectWebhookException(400, 'Invalid JSON payload');
        }

        // Validate webhook signature if secret is provided
        $signature = $request->headers->get('X-WC-Webhook-Signature');
        if ($secret && $signature) {
            if (!$this->webhookSecurityService->validateWooCommerceSignature($payload, $signature, $secret)) {
                throw new RejectWebhookException(401, 'Invalid signature');
            }
        } elseif ($secret && !$signature) {
            throw new RejectWebhookException(401, 'Missing signature header');
        }

        // Get webhook topic from header
        $topic = $request->headers->get('X-WC-Webhook-Topic');
        if (!$topic) {
            $this->logger->error('Missing X-WC-Webhook-Topic header');
            throw new RejectWebhookException(400, 'Missing webhook topic header');
        }

        // Check if this is an action-based webhook (new format)
        if (isset($webhookData['action']) && isset($webhookData['arg'])) {
            $this->logger->info('Processing action-based webhook', [
                'action' => $webhookData['action'],
                'order_id' => $webhookData['arg']
            ]);

            // Fetch the full order data from WooCommerce
            $orderId = (int) $webhookData['arg'];
            $orderData = $this->wooCommerceService->getOrder($orderId);

            if (!$orderData) {
                $this->logger->error('Failed to fetch order from WooCommerce', [
                    'order_id' => $orderId
                ]);

                throw new RejectWebhookException(400, 'Unable to fetch order data');
            }

            // Add webhook metadata
            $orderData['_webhook_topic'] = $topic;
            $orderData['_webhook_action'] = $webhookData['action'];

            return new RemoteEvent(
                'woocommerce',
                (string) $orderData['id'],
                $orderData
            );
        }

        // Handle legacy format (order data directly in payload)
        $orderData = $webhookData;

        // Validate that order ID exists in legacy format
        if (!isset($orderData['id'])) {
            $this->logger->error('Missing order ID in webhook payload');
            throw new RejectWebhookException(400, 'Missing order ID in payload');
        }

        // Add topic information to the payload
        $orderData['_webhook_topic'] = $topic;

        return new RemoteEvent(
            'woocommerce',
            (string) $orderData['id'],
            $orderData
        );
    }
}
