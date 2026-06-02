<?php

namespace App\MessageHandler;

use App\Message\IncomingWooCommerceWebhookMessage;
use App\Service\OrderPdfService;
use App\Service\WebhookSecurityService;
use App\Webhook\WooCommerceOrderNormalizationException;
use App\Webhook\WooCommerceOrderNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Consumes raw WooCommerce webhooks delivered via API Gateway -> SQS.
 *
 * This is the queue-side equivalent of the HTTP ingress
 * (WooCommerceRequestParser + WooCommerceWebhookController): it performs the same
 * signature validation, payload normalization, and completed-order check, then hands
 * off to the unchanged OrderPdfService::processOrder() pipeline.
 *
 * Failures are surfaced by throwing: recoverable issues (failed REST fetch) are retried
 * by Messenger and ultimately dead-lettered; invalid signatures/payloads are thrown as
 * UnrecoverableMessageHandlingException so they go straight to the DLQ without retries.
 */
#[AsMessageHandler]
class IncomingWooCommerceWebhookMessageHandler
{
    public function __construct(
        private WebhookSecurityService $webhookSecurityService,
        private WooCommerceOrderNormalizer $orderNormalizer,
        private OrderPdfService $orderPdfService,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(IncomingWooCommerceWebhookMessage $message): void
    {
        $payload = $message->getPayload();
        $signature = $message->getSignature();

        // 1. Validate the signature (was WooCommerceRequestParser::doParse on the HTTP path)
        if (!$signature) {
            $this->logger->error('Missing signature on ingested WooCommerce webhook');

            throw new UnrecoverableMessageHandlingException('Missing webhook signature');
        }

        if (!$this->webhookSecurityService->validateWooCommerceSignature($payload, $signature)) {
            // Signature mismatch will never become valid on retry: send straight to DLQ.
            throw new UnrecoverableMessageHandlingException('Invalid webhook signature');
        }

        // 2. Decode the payload
        $webhookData = json_decode($payload, true);

        if (!is_array($webhookData)) {
            $this->logger->error('Invalid JSON in ingested WooCommerce webhook');

            throw new UnrecoverableMessageHandlingException('Invalid JSON payload');
        }

        // 3. Normalize both webhook formats into a full order
        try {
            $orderData = $this->orderNormalizer->normalize(
                $webhookData,
                $message->getTopic(),
                $message->getEvent()
            );
        } catch (WooCommerceOrderNormalizationException $e) {
            // A missing order ID is unrecoverable; a failed REST fetch may be transient,
            // so let it retry (and eventually dead-letter) rather than discarding it.
            if ($e->getReason() === WooCommerceOrderNormalizationException::REASON_MISSING_ID) {
                throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
            }

            throw $e;
        }

        // 4. Only completed orders trigger the e-ticket email
        if (!$this->webhookSecurityService->isValidWooCommerceOrder($orderData)) {
            $this->logger->info('Skipping invalid, draft, or failed order', [
                'order_id' => $orderData['id'] ?? 'unknown',
                'status' => $orderData['status'] ?? 'unknown',
            ]);

            return;
        }

        $this->logger->info('WooCommerce order webhook received (ingest queue)', [
            'order_id' => $orderData['id'],
            'status' => $orderData['status'],
            'topic' => $message->getTopic(),
        ]);

        // 5. Hand off to the existing pipeline (generates PDF token + dispatches email)
        $this->orderPdfService->processOrder($orderData);
    }
}
