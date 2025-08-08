<?php

namespace App\Webhook;

use App\Service\WebhookSecurityService;
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
        private LoggerInterface $logger
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new MethodRequestMatcher(['POST']);
    }

    protected function doParse(Request $request, string $secret): ?RemoteEvent
    {
        $payload = $request->getContent();
        $orderData = json_decode($payload, true);

        if (!$orderData) {
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

      // Create event type based on WooCommerce webhook topic
        $topic = $request->headers->get('X-WC-Webhook-Topic', 'order.created');

      // Add topic information to the payload
        $orderData['_webhook_topic'] = $topic;

        return new RemoteEvent(
            'woocommerce', // Use the webhook type, not the event name
            (string) ($orderData['id'] ?? uniqid()),
            $orderData
        );
    }
}
