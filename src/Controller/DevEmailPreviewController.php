<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\OrderConfirmationEmailContextFactory;
use App\Service\SecurePdfStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class DevEmailPreviewController extends AbstractController
{
    public function __construct(
        private Environment $twig,
        private OrderConfirmationEmailContextFactory $emailContextFactory,
        private OrderRepository $orderRepository,
        private SecurePdfStorageService $securePdfStorageService,
    ) {
    }

    public function preview(string $orderNumber): Response
    {
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);
        if ($order === null) {
            throw $this->createNotFoundException('Order not found.');
        }

        $downloadToken = $this->securePdfStorageService->generateSecureTokenForOrder($order->orderNumber);

        return new Response($this->twig->render(
            'email/customer_order_confirmation.html.twig',
            $this->emailContextFactory->create($order, $downloadToken),
        ));
    }
}
