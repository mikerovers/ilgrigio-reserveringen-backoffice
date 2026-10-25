<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\LoginCodeService;
use App\Service\OrderConfirmationEmailContextFactory;
use App\Service\SecurePdfStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    /** Preview of the customer login code email; `?code=` overrides the sample code, `?format=txt` shows plain text. */
    public function loginCode(Request $request): Response
    {
        $code = preg_replace('/\D/', '', (string) $request->query->get('code', '')) ?: '123456';
        $context = [
            'code' => str_pad(substr($code, 0, 6), 6, '0', STR_PAD_LEFT),
            'ttl_minutes' => LoginCodeService::CODE_TTL_MINUTES,
        ];

        if ($request->query->get('format') === 'txt') {
            return new Response(
                $this->twig->render('email/login_code.txt.twig', $context),
                Response::HTTP_OK,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return new Response($this->twig->render('email/login_code.html.twig', $context));
    }
}
