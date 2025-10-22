<?php

namespace App\Controller;

use App\Service\OrderPdfService;
use App\Service\WooCommerceService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class OrderApiController extends AbstractController
{
    public function __construct(
        private OrderPdfService $orderPdfService,
        private WooCommerceService $wooCommerceService,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/orders/{orderId}/process', name: 'order_process', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function processOrder(int $orderId): JsonResponse
    {
        try {
            $this->logger->info('API order processing request received', [
                'order_id' => $orderId
            ]);

            // Fetch order data from WooCommerce
            $orderData = $this->wooCommerceService->getOrder($orderId);

            if (null === $orderData) {
                $this->logger->error('Order not found in WooCommerce', [
                    'order_id' => $orderId
                ]);

                return new JsonResponse([
                    'error' => 'Order not found',
                    'message' => "Order with ID {$orderId} could not be found in WooCommerce"
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate order data
            if (!$this->wooCommerceService->validateOrderData($orderData)) {
                return new JsonResponse([
                    'error' => 'Invalid order data',
                    'message' => 'Order data is missing required fields'
                ], Response::HTTP_BAD_REQUEST);
        }

            // Process the order through OrderPdfService
            $this->orderPdfService->processOrder($orderData);

            return new JsonResponse([
                'success' => true,
                'message' => 'Order processed successfully',
                'order_id' => $orderId
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('API order processing failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'error' => 'Failed to process order',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
