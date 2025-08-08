<?php

namespace App\Tests\Controller;

use App\Controller\TicketingController;
use App\Service\WooCommerceEventsService;
use App\Service\WooCommerceProductVariationsService;
use App\Service\WooCommerceCouponService;
use App\Service\WooCommerceService;
use App\Service\MollieService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;

class TicketingControllerThankYouTest extends TestCase
{
    private MockObject|WooCommerceEventsService $wooCommerceEventsService;
    private MockObject|WooCommerceProductVariationsService $productVariationsService;
    private MockObject|WooCommerceCouponService $couponService;
    private MockObject|WooCommerceService $wooCommerceService;
    private MockObject|MollieService $mollieService;
    private MockObject|ValidatorInterface $validator;
    private MockObject|LoggerInterface $logger;
    private MockObject|Environment $twig;

    protected function setUp(): void
    {
        $this->wooCommerceEventsService = $this->createMock(WooCommerceEventsService::class);
        $this->productVariationsService = $this->createMock(WooCommerceProductVariationsService::class);
        $this->couponService = $this->createMock(WooCommerceCouponService::class);
        $this->wooCommerceService = $this->createMock(WooCommerceService::class);
        $this->mollieService = $this->createMock(MollieService::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->twig = $this->createMock(Environment::class);
    }

    public function testThankYouWithValidOrderKeyFromSession(): void
    {
        // Set up session data
        $session = new Session(new MockArraySessionStorage());
        $session->set('order_id', 123);
        $session->set('customer_data', [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com'
        ]);

        // Create request without query parameters
        $request = new Request();
        $request->setSession($session);

        // Mock order data with key in direct field
        $orderData = [
            'id' => 123,
            'order_key' => 'wc_order_abcd123',
            'status' => 'completed',
            'meta_data' => []
        ];

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(123)
            ->willReturn($orderData);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getMolliePaymentId')
            ->with($orderData)
            ->willReturn('tr_payment_123');

        $this->mollieService
            ->expects($this->once())
            ->method('getPaymentStatus')
            ->with('tr_payment_123')
            ->willReturn([
                'success' => true,
                'status' => 'paid',
                'amount' => '25.00',
                'method' => 'ideal',
                'paid_at' => '2023-12-01T12:00:00Z'
            ]);

        $this->mollieService
            ->expects($this->once())
            ->method('getStatusMessage')
            ->with('paid')
            ->willReturn('Payment successful');

        $this->mollieService
            ->expects($this->once())
            ->method('isPaymentSuccessful')
            ->with('paid')
            ->willReturn(true);

        $this->mollieService
            ->expects($this->once())
            ->method('isPaymentPending')
            ->with('paid')
            ->willReturn(false);

        $this->mollieService
            ->expects($this->once())
            ->method('isPaymentFailed')
            ->with('paid')
            ->willReturn(false);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('ticketing/thank-you.html.twig', $this->isType('array'))
            ->willReturn('<html>Thank you page content</html>');

        $controller = $this->createController();
        $response = $controller->thankYou($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('Thank you page content', $response->getContent());
    }

    public function testThankYouWithValidOrderKeyFromQueryParams(): void
    {
        // Create request with query parameters
        $request = new Request(['order_id' => '456', 'key' => 'wc_order_xyz789']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        // Mock order data with key in meta_data
        $orderData = [
            'id' => 456,
            'status' => 'processing',
            'meta_data' => [
                [
                    'key' => '_order_key',
                    'value' => 'wc_order_xyz789'
                ],
                [
                    'key' => 'other_meta',
                    'value' => 'some_value'
                ]
            ]
        ];

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(456)
            ->willReturn($orderData);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getMolliePaymentId')
            ->with($orderData)
            ->willReturn(null); // No payment ID

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('ticketing/thank-you.html.twig', $this->isType('array'))
            ->willReturn('<html>Thank you page content</html>');

        $controller = $this->createController();
        $response = $controller->thankYou($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('Thank you page content', $response->getContent());
    }

    public function testThankYouWithMissingOrderKey(): void
    {
        // Create request with order_id but no key
        $request = new Request(['order_id' => '123']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Access denied to thank you page', $this->callback(function ($context) {
                return $context['error_code'] === 'missing_key' &&
                       $context['message'] === 'Order key is required';
            }));

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('error/access_denied.html.twig', $this->callback(function ($variables) {
                return $variables['error_code'] === 'missing_key' &&
                       $variables['title'] === 'Toegang Geweigerd';
            }))
            ->willReturn('<html>Access denied - missing key</html>');

        $controller = $this->createController();
        $response = $controller->thankYou($request);

        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('Access denied - missing key', $response->getContent());
    }

    public function testThankYouWithInvalidOrderKey(): void
    {
        // Create request with wrong key
        $request = new Request(['order_id' => '789', 'key' => 'wc_order_wrong123']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        // Mock order data
        $orderData = [
            'id' => 789,
            'order_key' => 'wc_order_correct123',
            'status' => 'pending',
            'meta_data' => []
        ];

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(789)
            ->willReturn($orderData);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Access denied to thank you page', $this->callback(function ($context) {
                return $context['error_code'] === 'invalid_key' &&
                       $context['message'] === 'Invalid order key';
            }));

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('error/access_denied.html.twig', $this->callback(function ($variables) {
                return $variables['error_code'] === 'invalid_key' &&
                       $variables['title'] === 'Toegang Geweigerd';
            }))
            ->willReturn('<html>Access denied - invalid key</html>');

        $controller = $this->createController();
        $response = $controller->thankYou($request);

        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('Access denied - invalid key', $response->getContent());
    }

    public function testThankYouWithNonExistentOrder(): void
    {
        // Create request for non-existent order
        $request = new Request(['order_id' => '999', 'key' => 'wc_order_any123']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $this->wooCommerceService
            ->expects($this->once())
            ->method('getOrder')
            ->with(999)
            ->willReturn(null); // Order not found

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Access denied to thank you page', $this->callback(function ($context) {
                return $context['error_code'] === 'order_not_found' &&
                       $context['message'] === 'Order not found';
            }));

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('error/access_denied.html.twig', $this->callback(function ($variables) {
                return $variables['error_code'] === 'order_not_found' &&
                       $variables['title'] === 'Toegang Geweigerd';
            }))
            ->willReturn('<html>Access denied - order not found</html>');

        $controller = $this->createController();
        $response = $controller->thankYou($request);

        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('Access denied - order not found', $response->getContent());
    }

    public function testThankYouWithOrderKeyInDifferentMetaDataFields(): void
    {
        // Test different meta_data key variations
        $testCases = [
            ['key' => '_order_key', 'value' => 'wc_order_meta1'],
            ['key' => 'order_key', 'value' => 'wc_order_meta2'],
            ['key' => '_woocommerce_order_key', 'value' => 'wc_order_meta3']
        ];

        foreach ($testCases as $index => $metaCase) {
            // Reset all mocks for each iteration
            $this->setUp();
            
            $orderId = 100 + $index;
            $orderData = [
                'id' => $orderId,
                'status' => 'completed',
                'meta_data' => [
                    $metaCase,
                    ['key' => 'unrelated_meta', 'value' => 'unrelated_value']
                ]
            ];

            // Create request with correct key from meta_data
            $request = new Request(['order_id' => (string)$orderId, 'key' => $metaCase['value']]);
            $session = new Session(new MockArraySessionStorage());
            $request->setSession($session);

            $this->wooCommerceService
                ->expects($this->once())
                ->method('getOrder')
                ->with($orderId)
                ->willReturn($orderData);

            $this->wooCommerceService
                ->expects($this->once())
                ->method('getMolliePaymentId')
                ->with($orderData)
                ->willReturn(null);

            $this->twig
                ->expects($this->once())
                ->method('render')
                ->with('ticketing/thank-you.html.twig', $this->isType('array'))
                ->willReturn('<html>Thank you page content</html>');

            $controller = $this->createController();
            $response = $controller->thankYou($request);

            $this->assertEquals(Response::HTTP_OK, $response->getStatusCode(), 
                "Failed for meta key: {$metaCase['key']}");
        }
    }

    public function testExtractOrderKeyMethod(): void
    {
        $controller = $this->createController();
        
        // Use reflection to test private method
        $reflectionClass = new \ReflectionClass($controller);
        $method = $reflectionClass->getMethod('extractOrderKey');
        $method->setAccessible(true);

        // Test direct order_key field
        $orderData1 = [
            'id' => 1,
            'order_key' => 'wc_order_direct123'
        ];
        $result1 = $method->invoke($controller, $orderData1);
        $this->assertEquals('wc_order_direct123', $result1);

        // Test order_key in meta_data
        $orderData2 = [
            'id' => 2,
            'meta_data' => [
                ['key' => '_order_key', 'value' => 'wc_order_meta456']
            ]
        ];
        $result2 = $method->invoke($controller, $orderData2);
        $this->assertEquals('wc_order_meta456', $result2);

        // Test no order_key found
        $orderData3 = [
            'id' => 3,
            'meta_data' => [
                ['key' => 'other_field', 'value' => 'other_value']
            ]
        ];
        $result3 = $method->invoke($controller, $orderData3);
        $this->assertNull($result3);

        // Test empty order_key
        $orderData4 = [
            'id' => 4,
            'order_key' => ''
        ];
        $result4 = $method->invoke($controller, $orderData4);
        $this->assertNull($result4);
    }

    private function createController(): TicketingController
    {
        // Create a mock controller that extends the original
        return new class(
            $this->wooCommerceEventsService,
            $this->productVariationsService,
            $this->couponService,
            $this->wooCommerceService,
            $this->mollieService,
            $this->validator,
            $this->logger,
            'https://example.com',
            10,
            21.0,
            $this->twig
        ) extends TicketingController {
            private Environment $twig;
            
            public function __construct(
                WooCommerceEventsService $wooCommerceEventsService,
                WooCommerceProductVariationsService $productVariationsService,
                WooCommerceCouponService $couponService,
                WooCommerceService $wooCommerceService,
                MollieService $mollieService,
                ValidatorInterface $validator,
                LoggerInterface $logger,
                string $ilgrigioBaseUrl,
                int $maxTicketsPerOrder,
                float $taxRate,
                Environment $twig
            ) {
                parent::__construct(
                    $wooCommerceEventsService,
                    $productVariationsService,
                    $couponService,
                    $wooCommerceService,
                    $mollieService,
                    $validator,
                    $logger,
                    $ilgrigioBaseUrl,
                    $maxTicketsPerOrder,
                    $taxRate
                );
                $this->twig = $twig;
            }
            
            protected function render(string $view, array $parameters = [], Response $response = null): Response
            {
                $content = $this->twig->render($view, $parameters);
                $response = $response ?: new Response();
                $response->setContent($content);
                return $response;
            }
        };
    }
}