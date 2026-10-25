<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\LoginCodeService;
use App\Service\SecurePdfStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/** Passwordless customer area: request an emailed code, log in, download tickets. */
final class CustomerAccountController extends AbstractController
{
    public const SESSION_EMAIL = 'customer_login_email';

    public function __construct(
        private LoginCodeService $loginCodeService,
        private OrderRepository $orderRepository,
        private SecurePdfStorageService $pdfStorageService,
        private LoggerInterface $logger,
        #[Autowire('%env(bool:CUSTOMER_LOGIN_ENABLED)%')]
        private bool $enabled,
        #[Autowire(service: 'limiter.login_code_request_ip')]
        private RateLimiterFactory $requestIpLimiter,
        #[Autowire(service: 'limiter.login_code_request_email')]
        private RateLimiterFactory $requestEmailLimiter,
    ) {
    }

    #[Route('/inloggen', name: 'customer_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $this->assertEnabled();

        if ($this->getUser() instanceof Customer) {
            return $this->redirectToRoute('customer_orders');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email = LoginCodeService::normaliseEmail((string) $request->request->get('email', ''));

            if (!$this->isCsrfTokenValid('customer_login_email', (string) $request->request->get('_csrf_token'))) {
                $error = 'Je sessie is verlopen. Probeer het opnieuw.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
                $error = 'Vul een geldig e-mailadres in.';
            } elseif (
                !$this->requestIpLimiter->create((string) $request->getClientIp())->consume()->isAccepted()
                || !$this->requestEmailLimiter->create($email)->consume()->isAccepted()
            ) {
                $this->logger->warning('Login code request rate limited', ['ip' => $request->getClientIp()]);
                $error = 'Te veel aanvragen. Probeer het over een kwartier opnieuw.';
            } else {
                // Same response whether or not the address is known (no enumeration).
                $this->loginCodeService->requestCode($email);
                $request->getSession()->set(self::SESSION_EMAIL, $email);

                return $this->redirectToRoute('customer_login_code');
            }
        }

        return $this->render('customer/login.html.twig', [
            'error' => $error,
            'email' => (string) $request->request->get('email', ''),
        ], new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Shows the code form; the POST is handled by EmailCodeAuthenticator. */
    #[Route('/inloggen/code', name: 'customer_login_code', methods: ['GET', 'POST'])]
    public function code(Request $request): Response
    {
        $this->assertEnabled();

        if ($this->getUser() instanceof Customer) {
            return $this->redirectToRoute('customer_orders');
        }

        $session = $request->getSession();
        $email = (string) $session->get(self::SESSION_EMAIL, '');

        if ($email === '') {
            return $this->redirectToRoute('customer_login');
        }

        return $this->render('customer/code.html.twig', [
            'email' => $email,
            'ttl_minutes' => LoginCodeService::CODE_TTL_MINUTES,
            'errors' => $session instanceof FlashBagAwareSessionInterface
                ? $session->getFlashBag()->get('login_error')
                : [],
        ]);
    }

    #[Route('/mijn-tickets', name: 'customer_orders', methods: ['GET'])]
    public function orders(): Response
    {
        $this->assertEnabled();

        $customer = $this->currentCustomer();

        $orders = $customer->orders
            ->filter(static fn (Order $order): bool => $order->status === OrderStatus::COMPLETED)
            ->toArray();
        usort($orders, static fn (Order $a, Order $b): int => $b->createdAt <=> $a->createdAt);

        return $this->render('customer/orders.html.twig', [
            'customer' => $customer,
            'orders' => $orders,
        ]);
    }

    #[Route('/mijn-tickets/{orderNumber}/pdf', name: 'customer_order_pdf', methods: ['GET'])]
    public function downloadPdf(string $orderNumber): Response
    {
        $this->assertEnabled();

        $customer = $this->currentCustomer();
        $order = $this->orderRepository->findOneByOrderNumber($orderNumber);

        // 404 (not 403) for other customers' orders so order numbers cannot be probed.
        if (
            $order === null
            || !$order->customer->id->equals($customer->id)
            || $order->status !== OrderStatus::COMPLETED
        ) {
            throw $this->createNotFoundException();
        }

        $response = new Response($this->pdfStorageService->generatePdfForOrder($order));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'tickets-' . $order->orderNumber . '.pdf',
        ));
        $response->headers->set('Cache-Control', 'private, no-store');

        $this->logger->info('Customer downloaded order PDF', [
            'order_number' => $order->orderNumber,
            'customer_id' => (string) $customer->id,
        ]);

        return $response;
    }

    /** Intercepted by the firewall's logout handler. */
    #[Route('/uitloggen', name: 'customer_logout', methods: ['GET'])]
    public function logout(): RedirectResponse
    {
        throw new \LogicException('Handled by the firewall logout.');
    }

    /** The customer area is switched by CUSTOMER_LOGIN_ENABLED; when off it does not exist. */
    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }
    }

    private function currentCustomer(): Customer
    {
        $user = $this->getUser();

        if (!$user instanceof Customer) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
