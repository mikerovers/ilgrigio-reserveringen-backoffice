<?php

namespace App\Controller;

use App\Service\OrderPdfService;
use App\Service\WebhookSecurityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[AsRemoteEventConsumer('woocommerce')]
class WooCommerceWebhookController implements ConsumerInterface
{
    public function __construct(
        private OrderPdfService $orderPdfService,
        private WebhookSecurityService $webhookSecurityService,
        private LoggerInterface $logger
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        try {
            $payload = $event->getPayload();

            if (!$payload) {
                $this->logger->error('Invalid webhook payload received');
                return;
            }

          // Extract topic information
            $topic = $payload['_webhook_topic'] ?? 'unknown';
            unset($payload['_webhook_topic']); // Remove internal field

          // Validate order data
            if (!$this->webhookSecurityService->isValidWooCommerceOrder($payload)) {
                $this->logger->info('Skipping invalid or draft order', ['order_id' => $payload['id'] ?? 'unknown']);

                return;
            }

            $this->logger->info('WooCommerce order webhook received', [
            'order_id' => $payload['id'],
            'status' => $payload['status'],
            'topic' => $topic
            ]);

          // Process the order (generate PDF and send email)
            $this->orderPdfService->processOrder($payload);
        } catch (\Exception $e) {
            $this->logger->error('Error processing WooCommerce webhook', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to let the webhook component handle it
        }
    }
}
