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
            static::getContainer()->get(\App\Service\TicketNameService::class),
            static::getContainer()->get(\App\Service\WooCommerceService::class),
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

        // Verify Content-Disposition header
        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString(
            'order-confirmation-ORDER-123.pdf',
            $contentDisposition
        );

        // CRITICAL: Verify PDF opens in browser (inline) instead of downloading
        $this->assertStringContainsString('inline', $contentDisposition, 'PDF should display inline in browser');
        $this->assertStringNotContainsString('attachment', $contentDisposition, 'PDF should NOT force download');
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
        $orderData = [
            'id' => 456,
            'number' => 'ORDER-456',
            'billing' => ['email' => 'test2@example.com']
        ];

        $wooCommerceService = $this->createMock(\App\Service\WooCommerceService::class);
        $wooCommerceService->method('getOrder')->with(456)->willReturn($orderData);

        $service = new SecurePdfStorageService(
            static::getContainer()->get('twig'),
            static::getContainer()->get(\App\Service\TicketApiService::class),
            static::getContainer()->get('logger'),
            static::getContainer()->get(\App\Service\TicketNameService::class),
            $wooCommerceService,
            'test-secret-key',
            150 // 150 days expiration (5 months)
        );

      // Generate token
        $token = $service->generateSecureToken($orderData);

      // Verify token is valid
        $this->assertTrue($service->isValidToken($token));

      // Verify order data can be retrieved (fetched from WooCommerce by order ID in token)
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
            static::getContainer()->get(\App\Service\TicketNameService::class),
            static::getContainer()->get(\App\Service\WooCommerceService::class),
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

    public function testPdfDisplaysInlineNotAsDownload(): void
    {
        $client = static::createClient();

        // Mock order data
        $orderData = [
            'id' => 999,
            'number' => 'TEST-999',
            'billing' => ['email' => 'inline-test@example.com']
        ];

        // Create a real service instance to generate a valid token
        $realService = new SecurePdfStorageService(
            static::getContainer()->get('twig'),
            static::getContainer()->get(\App\Service\TicketApiService::class),
            static::getContainer()->get('logger'),
            static::getContainer()->get(\App\Service\TicketNameService::class),
            static::getContainer()->get(\App\Service\WooCommerceService::class),
            'test-secret-key',
            150
        );
        $token = $realService->generateSecureToken($orderData);
        $pdfContent = '%PDF-1.4 test inline display';

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

        // Verify response is successful
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // Get the Content-Disposition header
        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($contentDisposition, 'Content-Disposition header must be set');

        // Verify it uses 'inline' to display in browser
        $this->assertMatchesRegularExpression(
            '/^inline\s*;/i',
            $contentDisposition,
            'Content-Disposition must start with "inline" to display PDF in browser'
        );

        // Verify it does NOT use 'attachment' which would force download
        $this->assertStringNotContainsString(
            'attachment',
            strtolower($contentDisposition),
            'Content-Disposition must NOT contain "attachment" - PDF should display in browser, not download'
        );

        // Verify filename is still included (browser can use it if user chooses to download)
        $this->assertStringContainsString(
            'filename=',
            $contentDisposition,
            'Filename should be included for user convenience'
        );
    }
}
