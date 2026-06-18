<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\OrderProcessResponse;
use App\Message\OrderCompleted;
use App\Repository\OrderRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
final class OrderApiController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Admin re-trigger of order fulfilment (re-issues tickets idempotently and
     * re-sends the confirmation email) for an existing order.
     */
    #[Route('/orders/{orderNumber}/process', name: 'order_process', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function processOrder(string $orderNumber): JsonResponse
    {
        $this->logger->info('API order processing request received', [
            'order_number' => $orderNumber,
        ]);

        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        if ($order === null) {
            return $this->json(
                new OrderProcessResponse(
                    success: false,
                    orderNumber: $orderNumber,
                    message: "Order {$orderNumber} could not be found",
                ),
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->messageBus->dispatch(new OrderCompleted((string) $order->id));

        return $this->json(new OrderProcessResponse(
            success: true,
            orderNumber: $orderNumber,
            message: 'Order fulfilment re-dispatched',
        ));
    }
}
