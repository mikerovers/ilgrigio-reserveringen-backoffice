<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class WebhookSecurityService
{
    public function __construct(
        private LoggerInterface $logger,
        private ?string $webhookSecret = null
    ) {
    }

    public function validateWooCommerceSignature(string $payload, string $signature, ?string $secret = null): bool
    {
        $webhookSecret = $secret ?? $this->webhookSecret;

        if (!$webhookSecret) {
            $this->logger->error('Webhook secret not configured - cannot validate signature');

            return false;
        }

        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $webhookSecret, true));

        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->error('Invalid webhook signature', [
            'expected' => $expectedSignature,
            'received' => $signature
            ]);
            return false;
        }

        return true;
    }

    public function isValidWooCommerceOrder(array $orderData): bool
    {
        $requiredFields = ['id', 'status'];

        foreach ($requiredFields as $field) {
            if (!isset($orderData[$field])) {
                $this->logger->error('Missing required field in order data', ['field' => $field]);

                return false;
            }
        }

      // Skip draft orders
        if (in_array($orderData['status'], ['draft', 'auto-draft'])) {
            return false;
        }

        return true;
    }
}
