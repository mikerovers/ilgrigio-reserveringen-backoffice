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
        private WooCommerceOrderNormalizer $orderNormalizer,
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

        // Normalize both webhook formats (legacy + action-based) into a full order
        try {
            $orderData = $this->orderNormalizer->normalize($webhookData, $topic);
        } catch (WooCommerceOrderNormalizationException $e) {
            throw new RejectWebhookException(400, $e->getMessage());
        }

        return new RemoteEvent(
            'woocommerce',
            (string) $orderData['id'],
            $orderData
        );
    }
}
