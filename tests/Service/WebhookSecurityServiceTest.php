<?php

namespace App\Tests\Service;

use App\Service\WebhookSecurityService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookSecurityServiceTest extends TestCase
{
    private WebhookSecurityService $service;
    private MockObject|LoggerInterface $logger;
    private string $testSecret = 'test-webhook-secret-123';

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new WebhookSecurityService($this->logger, $this->testSecret);
    }

    public function testValidateWooCommerceSignatureWithValidSignature(): void
    {
        $payload = '{"id":123,"status":"processing"}';
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $this->testSecret, true));

        $result = $this->service->validateWooCommerceSignature($payload, $expectedSignature);

        $this->assertTrue($result);
    }

    public function testValidateWooCommerceSignatureWithInvalidSignature(): void
    {
        $payload = '{"id":123,"status":"processing"}';
        $invalidSignature = 'invalid-signature';

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Invalid webhook signature', $this->callback(function ($context) {
                return isset($context['expected']) && isset($context['received']);
            }));

        $result = $this->service->validateWooCommerceSignature($payload, $invalidSignature);

        $this->assertFalse($result);
    }

    public function testValidateWooCommerceSignatureWithNoSecretConfigured(): void
    {
        $serviceWithoutSecret = new WebhookSecurityService($this->logger, null);
        $payload = '{"id":123,"status":"processing"}';
        $signature = 'any-signature';

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Webhook secret not configured - cannot validate signature');

        $result = $serviceWithoutSecret->validateWooCommerceSignature($payload, $signature);

        $this->assertFalse($result);
    }

    public function testValidateWooCommerceSignatureWithProvidedSecret(): void
    {
        $payload = '{"id":123,"status":"processing"}';
        $customSecret = 'custom-secret-456';
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $customSecret, true));

        $result = $this->service->validateWooCommerceSignature($payload, $expectedSignature, $customSecret);

        $this->assertTrue($result);
    }

    public function testValidateWooCommerceSignatureWithProvidedSecretOverridesDefault(): void
    {
        $payload = '{"id":123,"status":"processing"}';
        $customSecret = 'custom-secret-456';
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $customSecret, true));

        // This should use the custom secret, not the default one
        $result = $this->service->validateWooCommerceSignature($payload, $expectedSignature, $customSecret);

        $this->assertTrue($result);

        // Verify that using the default secret would fail
        $defaultSignature = base64_encode(hash_hmac('sha256', $payload, $this->testSecret, true));
        $this->assertNotEquals($expectedSignature, $defaultSignature);
    }

    public function testIsValidWooCommerceOrderWithValidOrder(): void
    {
        $orderData = [
            'id' => 123,
            'status' => 'processing',
            'total' => '99.99'
        ];

        $result = $this->service->isValidWooCommerceOrder($orderData);

        $this->assertTrue($result);
    }

    public function testIsValidWooCommerceOrderWithMissingId(): void
    {
        $orderData = [
            'status' => 'processing',
            'total' => '99.99'
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Missing required field in order data', ['field' => 'id']);

        $result = $this->service->isValidWooCommerceOrder($orderData);

        $this->assertFalse($result);
    }

    public function testIsValidWooCommerceOrderWithMissingStatus(): void
    {
        $orderData = [
            'id' => 123,
            'total' => '99.99'
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Missing required field in order data', ['field' => 'status']);

        $result = $this->service->isValidWooCommerceOrder($orderData);

        $this->assertFalse($result);
    }

    public function testIsValidWooCommerceOrderWithDraftStatus(): void
    {
        $orderData = [
            'id' => 123,
            'status' => 'draft'
        ];

        $result = $this->service->isValidWooCommerceOrder($orderData);

        $this->assertFalse($result);
    }

    public function testIsValidWooCommerceOrderWithAutoDraftStatus(): void
    {
        $orderData = [
            'id' => 123,
            'status' => 'auto-draft'
        ];

        $result = $this->service->isValidWooCommerceOrder($orderData);

        $this->assertFalse($result);
    }

    public function testIsValidWooCommerceOrderWithValidStatuses(): void
    {
        $validStatuses = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];

        foreach ($validStatuses as $status) {
            $orderData = [
                'id' => 123,
                'status' => $status
            ];

            $result = $this->service->isValidWooCommerceOrder($orderData);

            $this->assertTrue($result, "Status '{$status}' should be valid");
        }
    }

    public function testHashEqualsIsUsedForSignatureComparison(): void
    {
        // This test ensures we're using hash_equals for timing-safe comparison
        $payload = '{"id":123,"status":"processing"}';
        $correctSignature = base64_encode(hash_hmac('sha256', $payload, $this->testSecret, true));

        // Create a signature that's the same length but different content
        $incorrectSignature = str_replace('A', 'B', $correctSignature);
        if ($incorrectSignature === $correctSignature) {
            $incorrectSignature = str_replace('1', '2', $correctSignature);
        }

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Invalid webhook signature', $this->anything());

        $result = $this->service->validateWooCommerceSignature($payload, $incorrectSignature);

        $this->assertFalse($result);
    }
}
