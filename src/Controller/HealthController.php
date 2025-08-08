<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function healthCheck(): Response
    {
        return new JsonResponse([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'services' => [
                'webhook_endpoint' => '/api/webhook/woocommerce-order-created',
                'test_endpoint' => '/test/order-pdf'
            ]
        ]);
    }

    #[Route('/api/webhook/health', name: 'webhook_health_check', methods: ['GET'])]
    public function webhookHealthCheck(): Response
    {
        return new JsonResponse([
            'webhook_status' => 'ready',
            'endpoint' => '/api/webhook/woocommerce-order-created',
            'methods' => ['POST'],
            'timestamp' => date('c')
        ]);
    }
}
