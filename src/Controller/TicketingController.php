<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\CheckoutRequest;
use App\DTO\TicketOrderRequest;
use App\Entity\Event;
use App\Entity\TicketType;
use App\Enum\PaymentStatus;
use App\Enum\OrderStatus;
use App\Enum\StockStatus;
use App\Event\OrderCompletedEvent;
use App\Exception\CouponUnavailableException;
use App\Event\PaymentStatusChangedEvent;
use App\Payment\PaymentInitiatorInterface;
use App\Repository\EventRepository;
use App\Repository\OrderRepository;
use App\Repository\SitePageRepository;
use App\Service\CheckoutService;
use App\Service\CouponResolver;
use App\Service\MoneyService;
use App\Service\OrderPricingService;
use App\Service\StockReservationService;
use App\Service\VenueTime;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TicketingController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private OrderRepository $orderRepository,
        private SitePageRepository $sitePageRepository,
        private CouponResolver $couponResolver,
        private CheckoutService $checkoutService,
        private OrderPricingService $orderPricingService,
        private MoneyService $moneyService,
        private PaymentInitiatorInterface $paymentInitiator,
        private StockReservationService $stockReservationService,
        private EventDispatcherInterface $eventDispatcher,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $maxTicketsPerOrder,
        private float $taxRate,
    ) {
    }

    #[Route('/', name: 'app_tickets')]
    public function index(): Response
    {
        $events = array_map(
            fn (Event $e) => $this->eventView($e),
            $this->eventRepository->findUpcoming($this->clock->now()),
        );

        return $this->render('ticketing/index.html.twig', [
            'events' => $events,
            'homepage' => $this->sitePageRepository->findHomepage(),
        ]);
    }

    #[Route('/show/{slug}/tickets', name: 'app_event_tickets')]
    public function eventTickets(
        #[MapEntity(mapping: ['slug' => 'slug'])] Event $event,
        Request $request,
    ): Response {
        $remaining = $event->stockQuantity();
        if ($event->stockStatus() !== StockStatus::IN_STOCK || ($remaining !== null && $remaining <= 0)) {
            $this->addFlash('error', 'Deze show is uitverkocht en er kunnen geen tickets meer worden besteld.');

            return $this->redirectToRoute('app_tickets');
        }

        $session = $request->getSession();
        $cartItems = $session->get('cart_items', []);
        $existingQuantities = [];
        foreach ($cartItems as $item) {
            if (($item['event_id'] ?? null) === (string) $event->id) {
                $existingQuantities[$item['id']] = $item['quantity'];
            }
        }

        return $this->render('ticketing/tickets.html.twig', [
            'event' => $this->eventView($event),
            'ticketTypes' => array_map(fn (TicketType $t) => $this->ticketTypeView($t), $event->ticketTypes->toArray()),
            'maxTicketsPerOrder' => $this->maxTicketsPerOrder,
            'existingQuantities' => $existingQuantities,
            'appliedCoupon' => $session->get('applied_coupon'),
            'taxRate' => $this->taxRate,
            'sharedStock' => $event->displayStockQuantity(),
            'stockStatus' => $event->displayStockStatus()->value,
        ]);
    }

    #[Route('/show/{slug}/bestelling', name: 'app_tickets_order', methods: ['POST'])]
    public function processTicketOrder(
        #[MapEntity(mapping: ['slug' => 'slug'])] Event $event,
        #[MapRequestPayload] TicketOrderRequest $ticketOrder,
        Request $request,
    ): Response {
        $violations = $this->validator->validate($ticketOrder);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }

            return $this->redirectToRoute('app_event_tickets', ['slug' => $event->slug]);
        }

        // Map selected ticket types to a cart, pricing from entities only.
        $ticketTypesById = [];
        foreach ($event->ticketTypes as $ticketType) {
            $ticketTypesById[(string) $ticketType->id] = $ticketType;
        }

        $cartItems = [];
        $pricingCart = [];
        foreach ($ticketOrder->tickets as $ticketTypeId => $data) {
            $quantity = (int) ($data['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $ticketType = $ticketTypesById[(string) $ticketTypeId] ?? null;
            if ($ticketType === null || $ticketType->disabled) {
                continue;
            }

            $lineGross = $ticketType->price->multiply($quantity);
            $cartItems[] = [
                'id' => (string) $ticketType->id,
                'name' => $ticketType->name,
                'price' => $this->moneyService->toFloat($ticketType->price),
                'quantity' => $quantity,
                'total' => $this->moneyService->toFloat($lineGross),
                'event_id' => (string) $event->id,
                'event_name' => $event->name,
                'date' => VenueTime::toLocal($event->startsAt)->format('d-m-Y'),
            ];
            $pricingCart[] = ['ticketType' => $ticketType, 'quantity' => $quantity];
        }

        if ($cartItems === []) {
            $this->addFlash('error', 'Selecteer minimaal één ticket om door te gaan.');

            return $this->redirectToRoute('app_event_tickets', ['slug' => $event->slug]);
        }

        $coupon = $this->couponResolver->resolve($ticketOrder->appliedCouponCode);
        if ($coupon === null && trim((string) $ticketOrder->appliedCouponCode) !== '') {
            $this->addFlash('error', 'De kortingscode is ongeldig, verlopen of al gebruikt en is niet toegepast.');
        }

        $totals = $this->orderPricingService->calculate($pricingCart, $coupon, $this->taxRate);

        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $session->set('cart_items', $cartItems);
        $session->set('event_slug', $event->slug);
        $session->set('applied_coupon_code', $coupon?->code);
        $session->set('applied_coupon', $coupon !== null ? ['code' => $coupon->code] : null);
        $session->set('discount', $this->moneyService->toFloat($totals->discountGross));
        $session->set('event_data', $this->eventView($event));
        $session->save();

        return $this->redirectToRoute('app_checkout');
    }

    #[Route('/afrekenen', name: 'app_checkout')]
    public function checkout(Request $request): Response
    {
        $session = $request->getSession();
        $cartItems = $session->get('cart_items', []);
        if ($cartItems === []) {
            $this->addFlash('error', 'Je winkelwagen is leeg. Selecteer eerst tickets om af te rekenen.');

            return $this->redirectToRoute('app_tickets');
        }

        $checkoutToken = $session->get('checkout_token');
        if (!is_string($checkoutToken)) {
            $checkoutToken = bin2hex(random_bytes(32));
            $session->set('checkout_token', $checkoutToken);
        }

        $eventSlug = $session->get('event_slug');
        $event = is_string($eventSlug) ? $this->eventRepository->findOneBySlug($eventSlug) : null;
        if ($event !== null) {
            $ticketTypesById = [];
            foreach ($event->ticketTypes as $ticketType) {
                $ticketTypesById[(string) $ticketType->id] = $ticketType;
            }

            foreach ($cartItems as &$item) {
                $ticketType = $ticketTypesById[(string) ($item['id'] ?? '')] ?? null;
                if ($ticketType === null) {
                    continue;
                }

                $item['price'] = $this->moneyService->toFloat($ticketType->price);
                $item['total'] = $this->moneyService->toFloat($ticketType->price->multiply((int) $item['quantity']));
            }
            unset($item);
            $session->set('cart_items', $cartItems);
        }

        $ticketRows = array_map(fn (array $item): array => [
            'price' => $this->moneyService->eurosToMoney((float) ($item['price'] ?? 0)),
            'quantity' => (int) ($item['quantity'] ?? 0),
        ], $cartItems);
        $couponCode = $session->get('applied_coupon_code');
        $coupon = $this->couponResolver->resolve($couponCode);
        if ($couponCode !== null && $coupon === null) {
            $this->dropSessionCoupon($session);
            $this->addFlash('error', 'Je kortingscode is niet meer geldig en is verwijderd. Controleer het totaalbedrag.');
        }

        $totals = $this->orderPricingService->calculateFromTicketRows($ticketRows, $coupon, $this->taxRate);

        return $this->render('ticketing/checkout.html.twig', [
            'cart_items' => $cartItems,
            'event' => $session->get('event_data'),
            'applied_coupon' => $session->get('applied_coupon'),
            'subtotal' => $this->moneyService->toFloat($totals->subtotalNet),
            'discount' => $this->moneyService->toFloat($totals->discountGross),
            'tax' => $this->moneyService->toFloat($totals->tax),
            'total' => $this->moneyService->toFloat($totals->totalGross),
            'taxRate' => $this->taxRate,
            'checkout_token' => $checkoutToken,
        ]);
    }

    #[Route('/afrekenen/verwerken', name: 'app_checkout_process', methods: ['POST'])]
    public function processCheckout(
        #[MapRequestPayload] CheckoutRequest $checkout,
        Request $request,
    ): Response {
        $session = $request->getSession();

        // Checkout-token dedup (one-time use), unchanged.
        $sessionToken = $session->get('checkout_token');

        if (!$checkout->checkoutToken || !$sessionToken || $checkout->checkoutToken !== $sessionToken) {
            $this->logger->warning('Invalid or missing checkout token', [
                'has_session_token' => !empty($sessionToken),
            ]);
            $this->addFlash('error', 'Deze bestelling is al verwerkt of de sessie is verlopen. Start opnieuw.');

            return $this->redirectToRoute('app_checkout');
        }
        $session->remove('checkout_token');

        $violations = $this->validator->validate($checkout);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }

            return $this->redirectToRoute('app_checkout');
        }

        $cartItems = $session->get('cart_items', []);
        if ($cartItems === []) {
            $this->addFlash('error', 'Geen items in winkelwagen gevonden.');

            return $this->redirectToRoute('app_tickets');
        }

        // Rebuild the cart against current entities (authoritative prices/stock).
        $eventSlug = $session->get('event_slug');
        $event = $eventSlug ? $this->eventRepository->findOneBySlug($eventSlug) : null;
        if ($event === null) {
            $this->addFlash('error', 'De show kon niet worden gevonden.');

            return $this->redirectToRoute('app_tickets');
        }

        $ticketTypesById = [];
        foreach ($event->ticketTypes as $ticketType) {
            $ticketTypesById[(string) $ticketType->id] = $ticketType;
        }

        $cart = [];
        foreach ($cartItems as $item) {
            $ticketType = $ticketTypesById[(string) $item['id']] ?? null;
            if ($ticketType !== null) {
                $cart[] = ['ticketType' => $ticketType, 'quantity' => (int) $item['quantity']];
            }
        }

        $couponCode = $session->get('applied_coupon_code');
        $coupon = $this->couponResolver->resolve($couponCode);
        if ($couponCode !== null && $coupon === null) {
            // Never charge full price behind the customer's back: show the new total first.
            $this->dropSessionCoupon($session);
            $this->addFlash('error', 'Je kortingscode is niet meer geldig en is verwijderd. Controleer het totaalbedrag.');

            return $this->redirectToRoute('app_checkout');
        }

        $customer = $this->checkoutService->resolveCustomer(
            $checkout->email,
            $checkout->firstName,
            $checkout->lastName,
            $checkout->companyName ?: null,
            $checkout->city ?: null,
            $checkout->phoneNumber ?: null,
        );

        try {
            $order = $this->checkoutService->createOrder($cart, $customer, $coupon, $this->clock->now());
        } catch (CouponUnavailableException) {
            $this->dropSessionCoupon($session);
            $this->addFlash('error', 'Je kortingscode is niet meer beschikbaar en is verwijderd. Controleer het totaalbedrag.');

            return $this->redirectToRoute('app_checkout');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_tickets');
        }

        // Snapshot the billing details on the order.
        $order->billing->firstName = $checkout->firstName;
        $order->billing->lastName = $checkout->lastName;
        $order->billing->companyName = $checkout->companyName ?: null;
        $order->billing->city = $checkout->city;
        $order->billing->phoneNumber = $checkout->phoneNumber ?: null;
        $order->billing->email = $checkout->email;

        $session->set('order_number', $order->orderNumber);

        // Zero-total bypass: complete immediately and fan out fulfilment.
        if ($order->totalGross->isZero()) {
            $order->completeNow($this->clock->now());
            $this->checkoutService->save($order);
            $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));

            $this->addFlash('success', 'Je bestelling is geplaatst! Geen betaling vereist.');

            return $this->redirectToRoute('app_thank_you');
        }

        // Paid path: hand off to the payment provider (Omnipay, pending).
        $this->checkoutService->save($order);
        try {
            $redirectUrl = $this->paymentInitiator->initiate($order);
        } catch (\Throwable $e) {
            // No payment will ever arrive for this order: hand its stock back right away.
            $this->logger->error('Payment initiation failed', [
                'order_number' => $order->orderNumber,
                'error' => $e->getMessage(),
            ]);
            $order->status = OrderStatus::FAILED;
            $this->stockReservationService->releaseForOrder($order);
            $this->checkoutService->save($order);

            $this->addFlash('error', 'De betaling kon niet worden gestart. Probeer het later opnieuw.');

            return $this->redirectToRoute('app_tickets');
        }

        // Record payment initiation in the order history.
        $this->eventDispatcher->dispatch(new PaymentStatusChangedEvent($order, null, PaymentStatus::OPEN));

        return $this->redirect($redirectUrl);
    }

    #[Route('/algemene-voorwaarden/', name: 'app_terms_conditions')]
    public function termsConditions(): Response
    {
        return $this->render('ticketing/terms-conditions.html.twig');
    }

    #[Route('/bedankt', name: 'app_thank_you')]
    public function thankYou(Request $request): Response
    {
        $session = $request->getSession();
        $orderNumber = $session->get('order_number');

        $order = $orderNumber ? $this->orderRepository->findOneByOrderNumber((string) $orderNumber) : null;

        $paymentStatus = null;
        $paymentInfo = null;
        $customerData = null;

        if ($order !== null) {
            $customerData = [
                'firstName' => $order->billing->firstName,
                'lastName' => $order->billing->lastName,
                'email' => $order->billing->email,
            ];
            if ($order->payment !== null) {
                $paymentStatus = $order->payment->status->value;
                $paymentInfo = [
                    'status_message' => $order->payment->status->label(),
                    'is_successful' => $order->payment->status->isSuccessful(),
                    'is_pending' => $order->payment->status->isPending(),
                    'is_failed' => $order->payment->status->isFailed(),
                ];
            }
        }

        // Clear checkout session state.
        foreach (['cart_items', 'order_number', 'event_data', 'event_slug', 'applied_coupon', 'applied_coupon_code', 'discount'] as $key) {
            $session->remove($key);
        }

        return $this->render('ticketing/thank-you.html.twig', [
            'order_id' => $orderNumber,
            'customer_data' => $customerData,
            'payment_status' => $paymentStatus,
            'payment_info' => $paymentInfo,
        ]);
    }

    private function dropSessionCoupon(SessionInterface $session): void
    {
        foreach (['applied_coupon_code', 'applied_coupon'] as $key) {
            $session->remove($key);
        }
        $session->set('discount', 0);
    }

    /**
     * Reshape an Event entity into the array shape the Twig templates expect.
     */
    private function eventView(Event $event): array
    {
        $startsAt = VenueTime::toLocal($event->startsAt);

        return [
            'id' => (string) $event->id,
            'slug' => $event->slug,
            'name' => $event->name,
            'description' => $event->description,
            'date' => $startsAt->format('d-m-Y'),
            'time' => $startsAt->format('H:i'),
            'formatted_date' => $startsAt->format('d M'),
            'location' => $event->location,
            'image_url' => $event->imageUrl,
            'permalink' => $event->permalink,
            'stock_status' => $event->displayStockStatus()->value,
            'stock_quantity' => $event->displayStockQuantity(),
        ];
    }

    /**
     * Reshape a TicketType entity into the array shape the Twig templates expect.
     */
    private function ticketTypeView(TicketType $ticketType): array
    {
        return [
            'id' => (string) $ticketType->id,
            'name' => $ticketType->name,
            'price' => $this->moneyService->toFloat($ticketType->price),
            'type' => $ticketType->name,
            'disabled' => $ticketType->disabled,
            'disabled_reason' => $ticketType->disabledReason,
            'shared_stock_id' => $ticketType->sharedStock !== null ? (string) $ticketType->sharedStock->id : null,
            'shared_stock_quantity' => $ticketType->sharedStock?->stockQuantity,
            'shared_stock_status' => $ticketType->sharedStock?->stockStatus->value,
        ];
    }
}
