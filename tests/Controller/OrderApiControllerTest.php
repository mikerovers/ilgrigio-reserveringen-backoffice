<?php

namespace App\Tests\Controller;

use App\Service\OrderPdfService;
use App\Service\WooCommerceService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class OrderApiControllerTest extends WebTestCase
{
    private const API_KEY = '51614179e60fdbe79271773ad044af4adaacaef13bb0206d9d73dffee75671cb';
    private MockObject|OrderPdfService $orderPdfService;
    private MockObject|WooCommerceService $wooCommerceService;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->orderPdfService = $this->createMock(OrderPdfService::class);
        $this->wooCommerceService = $this->createMock(WooCommerceService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testProcessOrderSuccessfully(): void
    {
        $client = static::createClient();

        $orderId = 12345;
        $orderData = [
            'id' => $orderId,
            'number' => '12345',
            'billing' => [
                'email' => 'customer@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe'
            ],
            'line_items' => [
                [
                    'name' => 'Event Ticket',
                    'quantity' => 2,
                    'total' => '50.00'
                ]
            ]
        ];

        // Configure WooCommerce service mock
        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with($orderId)
            ->willReturn($orderData);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('validateOrderData')
            ->with($orderData)
            ->willReturn(true);

        // Configure mock to expect processOrder to be called
        $this->orderPdfService
            ->expects($this->once())
            ->method('processOrder')
            ->with($orderData);

        // Replace services in the container
        static::getContainer()->set(OrderPdfService::class, $this->orderPdfService);
        static::getContainer()->set(WooCommerceService::class, $this->wooCommerceService);

        // Make the request with API key
        $client->request(
            'POST',
            "/api/orders/{$orderId}/process",
            [],
            [],
            ['HTTP_X-API-KEY' => self::API_KEY]
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($response->headers->contains('Content-Type', 'application/json'));

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['success']);
        $this->assertEquals('Order processed successfully', $content['message']);
        $this->assertEquals($orderId, $content['order_id']);
    }

    public function testProcessOrderWithoutAuthentication(): void
    {
        $client = static::createClient();

        $orderId = 12345;

        // Make the request WITHOUT API key
        $client->request(
            'POST',
            "/api/orders/{$orderId}/process"
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProcessOrderWithInvalidApiKey(): void
    {
        $client = static::createClient();

        $orderId = 12345;

        // Make the request with INVALID API key
        $client->request(
            'POST',
            "/api/orders/{$orderId}/process",
            [],
            [],
            ['HTTP_X-API-KEY' => 'invalid-api-key']
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication failed', $content['error']);
    }

    public function testProcessOrderWithOrderNotFound(): void
    {
        $client = static::createClient();

        $orderId = 99999;

        // Configure WooCommerce service mock to return null (order not found)
        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with($orderId)
            ->willReturn(null);

        // Replace services in the container
        static::getContainer()->set(WooCommerceService::class, $this->wooCommerceService);

        // Make the request
        $client->request(
            'POST',
            "/api/orders/{$orderId}/process",
            [],
            [],
            ['HTTP_X-API-KEY' => self::API_KEY]
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Order not found', $content['error']);
    }

    public function testProcessOrderWithInvalidOrderData(): void
    {
        $client = static::createClient();

        $orderId = 12345;
        $orderData = [
            'id' => $orderId,
            // Missing 'billing' field
        ];

        // Configure WooCommerce service mock
        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with($orderId)
            ->willReturn($orderData);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('validateOrderData')
            ->with($orderData)
            ->willReturn(false);

        // Replace services in the container
        static::getContainer()->set(WooCommerceService::class, $this->wooCommerceService);

        // Make the request
        $client->request(
            'POST',
            "/api/orders/{$orderId}/process",
            [],
            [],
            ['HTTP_X-API-KEY' => self::API_KEY]
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid order data', $content['error']);
    }

    public function testProcessOrderWithServiceException(): void
    {
        $client = static::createClient();

        $orderId = 12345;
        $orderData = [
            'id' => $orderId,
            'billing' => ['email' => 'customer@example.com']
        ];

        // Configure WooCommerce service mock
        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with($orderId)
            ->willReturn($orderData);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('validateOrderData')
            ->with($orderData)
            ->willReturn(true);

        // Configure mock to throw an exception
        $this->orderPdfService
            ->expects($this->once())
            ->method('processOrder')
            ->with($orderData)
            ->willThrowException(new \Exception('Service error'));

        // Replace services in the container
        static::getContainer()->set(OrderPdfService::class, $this->orderPdfService);
        static::getContainer()->set(WooCommerceService::class, $this->wooCommerceService);

        // Make the request
        $client->request(
            'POST',
            "/api/orders/{$orderId}/process",
            [],
            [],
            ['HTTP_X-API-KEY' => self::API_KEY]
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Failed to process order', $content['error']);
        $this->assertEquals('Service error', $content['message']);
    }
}
