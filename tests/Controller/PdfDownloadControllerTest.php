<?php

namespace App\Tests\Controller;

use App\Controller\PdfDownloadController;
use App\Service\SecurePdfStorageService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class PdfDownloadControllerTest extends WebTestCase
{
    private MockObject|SecurePdfStorageService $securePdfStorageService;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->securePdfStorageService = $this->createMock(SecurePdfStorageService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testDownloadPdfWithValidToken(): void
    {
        $client = static::createClient();

      // Mock order data
        $orderData = [
        'id' => 123,
        'number' => 'ORDER-123',
        'billing' => ['email' => 'test@example.com']
        ];

      // Create a real service instance to generate a valid token
        $realService = new SecurePdfStorageService(
            static::getContainer()->get('twig'),
            static::getContainer()->get(\App\Service\TicketApiService::class),
            static::getContainer()->get('logger'),
            'test-secret-key',
            150 // 150 days expiration (5 months)
        );
        $token = $realService->generateSecureToken($orderData);
        $pdfContent = '%PDF-1.4 fake pdf content';

      // Configure mocks
        $this->securePdfStorageService
        ->method('isValidToken')
        ->with($token)
        ->willReturn(true);

        $this->securePdfStorageService
        ->method('getOrderDataByToken')
        ->with($token)
        ->willReturn($orderData);

        $this->securePdfStorageService
        ->method('getPdfByToken')
        ->with($token)
        ->willReturn($pdfContent);

      // Replace the service in the container
        static::getContainer()->set(SecurePdfStorageService::class, $this->securePdfStorageService);

      // Make the request
        $client->request('GET', '/pdf/download/' . $token);

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertEquals($pdfContent, $response->getContent());
        $this->assertStringContainsString(
            'order-confirmation-ORDER-123.pdf',
            $response->headers->get('Content-Disposition')
        );
    }

    public function testDownloadPdfWithInvalidToken(): void
    {
        $client = static::createClient();

        $token = 'invalid-token';

      // Configure mocks
        $this->securePdfStorageService
        ->method('isValidToken')
        ->with($token)
        ->willReturn(false);

      // Replace the service in the container
        static::getContainer()->set(SecurePdfStorageService::class, $this->securePdfStorageService);

      // Make the request
        $client->request('GET', '/pdf/download/' . $token);

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testTokenGenerationAndValidation(): void
    {
        $service = new SecurePdfStorageService(
            static::getContainer()->get('twig'),
            static::getContainer()->get(\App\Service\TicketApiService::class),
            static::getContainer()->get('logger'),
            'test-secret-key',
            150 // 150 days expiration (5 months)
        );

        $orderData = [
        'id' => 456,
        'number' => 'ORDER-456',
        'billing' => ['email' => 'test2@example.com']
        ];

      // Generate token
        $token = $service->generateSecureToken($orderData);

      // Verify token is valid
        $this->assertTrue($service->isValidToken($token));

      // Verify order data can be retrieved
        $retrievedData = $service->getOrderDataByToken($token);
        $this->assertEquals($orderData, $retrievedData);

      // Verify invalid token returns false
        $this->assertFalse($service->isValidToken('invalid-token'));
    }

    public function testTokenExpiration(): void
    {
        $service = new SecurePdfStorageService(
            static::getContainer()->get('twig'),
            static::getContainer()->get(\App\Service\TicketApiService::class),
            static::getContainer()->get('logger'),
            'test-secret-key',
            0 // 0 days expiration - should expire immediately
        );

        $orderData = [
        'id' => 789,
        'number' => 'ORDER-789',
        'billing' => ['email' => 'test3@example.com']
        ];

      // Generate token that should be expired
        $token = $service->generateSecureToken($orderData);

      // Wait a moment to ensure expiration
        sleep(1);

      // Token should be invalid due to expiration
        $this->assertFalse($service->isValidToken($token));
        $this->assertNull($service->getOrderDataByToken($token));
        $this->assertNull($service->getPdfByToken($token));
    }
}
