<?php

namespace App\Controller;

use App\DTO\CheckoutDTO;
use App\DTO\TicketOrderDTO;
use App\Service\WooCommerceEventsService;
use App\Service\WooCommerceProductVariationsService;
use App\Service\WooCommerceCouponService;
use App\Service\WooCommerceService;
use App\Service\MollieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Psr\Log\LoggerInterface;

class TicketingController extends AbstractController
{
    public function __construct(
        private WooCommerceEventsService $wooCommerceEventsService,
        private WooCommerceProductVariationsService $productVariationsService,
        private WooCommerceCouponService $couponService,
        private WooCommerceService $wooCommerceService,
        private MollieService $mollieService,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
        private string $ilgrigioBaseUrl,
        private int $maxTicketsPerOrder,
        private float $taxRate
    ) {
    }

    #[Route('/', name: 'app_tickets')]
    public function index(): Response
    {
        $events = $this->wooCommerceEventsService->getEvents();

        return $this->render('ticketing/index.html.twig', [
            'events' => $events,
        ]);
    }

    #[Route('/show/{id}', name: 'app_event_details')]
    public function eventDetails(int $id): Response
    {
        // This would redirect to the WooCommerce product page
        // For now, just redirect to the WooCommerce site
        return $this->redirect("{$this->ilgrigioBaseUrl}/product/bombolini-event-{$id}/");
    }

    #[Route('/show/{id}/tickets', name: 'app_event_tickets')]
    public function eventTickets(int $id, Request $request): Response
    {
        // Get event details
        $events = $this->wooCommerceEventsService->getEvents();
        $event = null;

        foreach ($events as $e) {
            if ($e['id'] == $id) {
                $event = $e;
                break;
            }
        }

        if (!$event) {
            throw $this->createNotFoundException('Event not found');
        }

        // Check if event is sold out or has no stock
        if ($event['stock_status'] !== 'instock' || $event['stock_quantity'] <= 0) {
            $this->addFlash('error', 'Deze show is uitverkocht en er kunnen geen tickets meer worden besteld.');
            return $this->redirectToRoute('app_tickets');
        }

        // Get product variations (ticket types)
        $ticketTypes = $this->productVariationsService->getProductVariations($id);

        // Get existing cart data from session to pre-fill quantities
        $session = $request->getSession();
        $cartItems = $session->get('cart_items', []);
        $existingQuantities = [];
        $appliedCoupon = $session->get('applied_coupon');

        // Extract quantities for this event from cart
        foreach ($cartItems as $item) {
            if (isset($item['event_id']) && $item['event_id'] == $id) {
                $existingQuantities[$item['id']] = $item['quantity'];
            }
        }

        // Get shared stock from event (stock is shared across all ticket types)
        $sharedStock = $event['stock_quantity'] ?? null;
        $stockStatus = $event['stock_status'] ?? 'instock';

        return $this->render('ticketing/tickets.html.twig', [
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'maxTicketsPerOrder' => $this->maxTicketsPerOrder,
            'existingQuantities' => $existingQuantities,
            'appliedCoupon' => $appliedCoupon,
            'taxRate' => $this->taxRate,
            'sharedStock' => $sharedStock,
            'stockStatus' => $stockStatus,
        ]);
    }

    #[Route('/show/{id}/bestelling', name: 'app_tickets_order', methods: ['POST'])]
    public function processTicketOrder(
        int $id,
        array $event,
        TicketOrderDTO $ticketOrderDTO,
        Request $request
    ): Response {
        $violations = $this->validator->validate($ticketOrderDTO);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }

            return $this->redirectToRoute('app_event_tickets', ['id' => $id]);
        }

        $selectedTickets = [];
        $totalAmount = 0;

        // Validate and process ticket selections
        foreach ($ticketOrderDTO->tickets as $ticketId => $data) {
            $quantity = (int) ($data['quantity'] ?? 0);

            if ($quantity > 0) {
                $selectedTickets[] = [
                    'id' => $ticketId,
                    'name' => $data['name'] ?? '',
                    'price' => (float) ($data['price'] ?? 0),
                    'quantity' => $quantity,
                    'total' => $quantity * (float) ($data['price'] ?? 0)
                ];
                $totalAmount += $quantity * (float) ($data['price'] ?? 0);
            }
        }

        // Calculate totals with tax-inclusive pricing
        $totalAmount = 0;
        foreach ($selectedTickets as $ticket) {
            $totalAmount += $ticket['total'];
        }

        // Apply discount to the total (which includes tax)
        $totalAfterDiscount = $totalAmount;
        if ($ticketOrderDTO->appliedCoupon && $ticketOrderDTO->discountAmount > 0) {
            // Validate the discount amount by recalculating it
            $calculatedDiscount = $this->couponService->calculateDiscount($ticketOrderDTO->appliedCoupon, $totalAmount);

            // Use the calculated discount if it matches the submitted one (with small tolerance for floating point)
            if (abs($calculatedDiscount - $ticketOrderDTO->discountAmount) < 0.01) {
                $totalAfterDiscount = $totalAmount - $ticketOrderDTO->discountAmount;
            }
        }

        // Calculate tax components (tax is included in the total)
        $taxRate = $this->taxRate / 100; // Convert percentage to decimal
        $taxAmount = $totalAfterDiscount - ($totalAfterDiscount / (1 + $taxRate));
        $subtotalWithoutTax = $totalAfterDiscount - $taxAmount;

        $finalTotal = $totalAfterDiscount;

        // Validate that at least one ticket is selected
        if (empty($selectedTickets)) {
            $this->addFlash('error', 'Selecteer minimaal één ticket om door te gaan.');

            return $this->redirectToRoute('app_event_tickets', ['id' => $id]);
        }

        // Store order data in session for checkout
        $session = $request->getSession();
        $cartItems = [];

        foreach ($selectedTickets as $ticket) {
            $cartItems[] = [
                'id' => $ticket['id'],
                'name' => $ticket['name'],
                'price' => $ticket['price'],
                'quantity' => $ticket['quantity'],
                'total' => $ticket['total'],
                'event_id' => $id,
                'event_name' => $event['title'] ?? 'Event',
                'date' => $event['date'] ?? null,
            ];
        }

        $session->set('cart_items', $cartItems);
        $session->set('subtotal', $totalAmount);
        $session->set('discount', $ticketOrderDTO->discountAmount);
        $session->set('tax', $taxAmount);
        $session->set('total', $finalTotal);
        $session->set('applied_coupon', $ticketOrderDTO->appliedCoupon);
        $session->set('event_data', $event);

        // Redirect to our custom checkout page instead of WooCommerce
        return $this->redirectToRoute('app_checkout');
    }

    #[Route('/api/available-events', name: 'app_available_events')]
    public function availableEvents(): JsonResponse
    {
        $events = $this->wooCommerceEventsService->getAvailableEvents();

        return $this->json($events);
    }

    #[Route('/api/validate-coupon', name: 'app_validate_coupon', methods: ['POST'])]
    public function validateCoupon(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $couponCode = $data['code'] ?? '';

        if (empty($couponCode)) {
            return $this->json([
                'valid' => false,
                'message' => 'Coupon code is required'
            ], 400);
        }

        $validationResult = $this->couponService->validateCoupon($couponCode);

        // If coupon is valid, store it in session
        if ($validationResult['valid']) {
            $session = $request->getSession();
            $session->set('applied_coupon', $validationResult);

            // Recalculate totals with the discount
            $cartItems = $session->get('cart_items', []);
            $subtotal = 0;
            foreach ($cartItems as $item) {
                $subtotal += $item['total'] ?? 0;
            }

            // Calculate discount on the total (which includes tax)
            $discountAmount = 0;
            if ($validationResult['discount_type'] === 'percentage') {
                $discountAmount = ($subtotal * $validationResult['amount']) / 100;
            } else {
                $discountAmount = $validationResult['amount'];
            }
            $discountAmount = min($discountAmount, $subtotal);

            $totalAfterDiscount = $subtotal - $discountAmount;

            // Calculate tax components (tax is included in the total)
            $taxRate = $this->taxRate / 100; // Convert percentage to decimal
            $taxAmount = $totalAfterDiscount - ($totalAfterDiscount / (1 + $taxRate));
            $subtotalWithoutTax = $totalAfterDiscount - $taxAmount;

            $total = $totalAfterDiscount;

            // Update session with new totals
            $session->set('discount', $discountAmount);
            $session->set('tax', $taxAmount);
            $session->set('subtotal_without_tax', $subtotalWithoutTax);
            $session->set('total', $total);
        }

        return $this->json($validationResult);
    }

    #[Route('/api/remove-coupon', name: 'app_remove_coupon', methods: ['POST'])]
    public function removeCoupon(Request $request): JsonResponse
    {
        $session = $request->getSession();

        // Remove coupon from session
        $session->remove('applied_coupon');

        // Recalculate totals without discount (but still tax-inclusive)
        $cartItems = $session->get('cart_items', []);
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['total'] ?? 0;
        }

        // Calculate tax components (tax is included in the total)
        $taxRate = $this->taxRate / 100; // Convert percentage to decimal
        $taxAmount = $subtotal - ($subtotal / (1 + $taxRate));
        $subtotalWithoutTax = $subtotal - $taxAmount;

        // Reset discount and update totals
        $session->set('discount', 0);
        $session->set('tax', $taxAmount);
        $session->set('subtotal_without_tax', $subtotalWithoutTax);
        $session->set('total', $subtotal);

        return $this->json([
            'success' => true,
            'message' => 'Coupon removed successfully'
        ]);
    }

    #[Route('/afrekenen', name: 'app_checkout')]
    public function checkout(Request $request): Response
    {
        // Get cart data from session or request
        $session = $request->getSession();
        $cartItems = $session->get('cart_items', []);
        $eventData = $session->get('event_data', null);
        $appliedCoupon = $session->get('applied_coupon', null);

        // Redirect to events page if cart is empty
        if (empty($cartItems)) {
            $this->addFlash('error', 'Je winkelwagen is leeg. Selecteer eerst tickets om af te rekenen.');
            return $this->redirectToRoute('app_tickets');
        }

        // Generate a unique checkout token to prevent duplicate submissions
        $checkoutToken = bin2hex(random_bytes(32));
        $session->set('checkout_token', $checkoutToken);

        // Calculate totals (tax-inclusive pricing)
        $totalWithTax = 0;
        foreach ($cartItems as $item) {
            $totalWithTax += $item['total'] ?? 0;
        }

        $discount = $session->get('discount', 0);
        $tax = $session->get('tax', 0);
        $total = $session->get('total', $totalWithTax);

        // If tax is not in session, calculate it (tax-inclusive)
        if ($tax === 0) {
            $totalAfterDiscount = $totalWithTax - $discount;
            $taxRate = $this->taxRate / 100; // Convert percentage to decimal
            $tax = $totalAfterDiscount - ($totalAfterDiscount / (1 + $taxRate));
            $total = $totalAfterDiscount;
        }

        // Calculate subtotal without tax
        $subtotal = $total - $tax;

        return $this->render('ticketing/checkout.html.twig', [
            'cart_items' => $cartItems,
            'event' => $eventData,
            'applied_coupon' => $appliedCoupon,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'taxRate' => $this->taxRate,
            'checkout_token' => $checkoutToken,
        ]);
    }

    #[Route('/afrekenen/verwerken', name: 'app_checkout_process', methods: ['POST'])]
    public function processCheckout(CheckoutDTO $checkoutDTO, Request $request): Response
    {
        // Get session first for token validation
        $session = $request->getSession();

        // Validate checkout token to prevent duplicate submissions
        $submittedToken = $request->request->get('checkout_token');
        $sessionToken = $session->get('checkout_token');

        if (!$submittedToken || !$sessionToken || $submittedToken !== $sessionToken) {
            $this->logger->warning('Invalid or missing checkout token', [
                'submitted_token' => $submittedToken,
                'has_session_token' => !empty($sessionToken)
            ]);
            $this->addFlash('error', 'Deze bestelling is al verwerkt of de sessie is verlopen. Start opnieuw.');
            return $this->redirectToRoute('app_tickets');
        }

        // Immediately invalidate the token to prevent reuse (one-time use)
        $session->remove('checkout_token');

        $violations = $this->validator->validate($checkoutDTO);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('error', $violation->getMessage());
            }
            return $this->redirectToRoute('app_checkout');
        }

        // Get cart data from session
        $cartItems = $session->get('cart_items', []);
        $eventData = $session->get('event_data', null);
        $appliedCoupon = $session->get('applied_coupon', null);
        $total = $session->get('total', 0);
        $discount = $session->get('discount', 0);
        $tax = $session->get('tax', 0);

        if (empty($cartItems)) {
            $this->addFlash('error', 'Geen items in winkelwagen gevonden.');
            return $this->redirectToRoute('app_tickets');
        }

        // Prepare order data for WooCommerce
        $orderData = [
            'payment_method' => 'mollie_wc_gateway_ideal',
            'payment_method_title' => 'iDEAL',
            'set_paid' => false,
            'status' => 'pending',
            'currency' => 'EUR',
            'billing' => [
                'first_name' => $checkoutDTO->firstName,
                'last_name' => $checkoutDTO->lastName,
                'company' => $checkoutDTO->companyName,
                'city' => $checkoutDTO->city,
                'phone' => $checkoutDTO->phoneNumber,
                'email' => $checkoutDTO->email,
            ],
            'shipping' => [
                'first_name' => $checkoutDTO->firstName,
                'last_name' => $checkoutDTO->lastName,
                'company' => $checkoutDTO->companyName,
                'city' => $checkoutDTO->city,
            ],
            'line_items' => [],
            'coupon_lines' => [],
            'shipping_lines' => [],
            'fee_lines' => [],
            'tax_lines' => [
                [
                    'rate_code' => 'NL-VAT-' . $this->taxRate,
                    'rate_id' => '1',
                    'label' => 'BTW',
                    'compound' => false,
                    'tax_total' => (string) number_format($tax, 2, '.', ''),
                    'shipping_tax_total' => '0.00'
                ]
            ],
            'meta_data' => [
                [
                    'key' => '_event_name',
                    'value' => $eventData['title'] ?? 'Event'
                ],
                [
                    'key' => '_event_date',
                    'value' => $eventData['date'] ?? ''
                ]
            ]
        ];

        // Add line items
        foreach ($cartItems as $item) {
            // Calculate net price and tax per item (since prices are tax-inclusive)
            $taxRate = $this->taxRate / 100;
            $itemTotalWithTax = $item['total'];
            $itemTotalWithoutTax = $itemTotalWithTax / (1 + $taxRate);
            $itemTaxAmount = $itemTotalWithTax - $itemTotalWithoutTax;

            $orderData['line_items'][] = [
                'product_id' => $item['id'],
                'quantity' => $item['quantity'],
                'name' => $item['name'],
                'price' => number_format($itemTotalWithoutTax / $item['quantity'], 2, '.', ''),
                'total' => (string) number_format($itemTotalWithoutTax, 2, '.', ''),
                'total_tax' => (string) number_format($itemTaxAmount, 2, '.', ''),
                'taxes' => [
                    [
                        'id' => 1,
                        'total' => (string) number_format($itemTaxAmount, 2, '.', ''),
                        'subtotal' => (string) number_format($itemTaxAmount, 2, '.', '')
                    ]
                ]
            ];
        }

        // Add coupon if applied
        if ($appliedCoupon && $discount > 0) {
            // Calculate discount tax (since discount is applied to tax-inclusive amount)
            $taxRate = $this->taxRate / 100;
            $discountWithoutTax = $discount / (1 + $taxRate);
            $discountTax = $discount - $discountWithoutTax;

            $orderData['coupon_lines'][] = [
                'code' => $appliedCoupon['code'],
                'discount' => (string) number_format($discountWithoutTax, 2, '.', ''),
                'discount_tax' => (string) number_format($discountTax, 2, '.', '')
            ];
        }

        // Create order in WooCommerce
        $orderResult = $this->wooCommerceService->createOrder($orderData);

        if (!$orderResult['success']) {
            $errorMessage = 'Er is een fout opgetreden bij het aanmaken van de bestelling: '
                . $orderResult['message'];
            $this->addFlash('error', $errorMessage);

            return $this->redirectToRoute('app_checkout');
        }

        $order = $orderResult['order'];
        $orderId = $order['id'];

        // Store order ID in session for thank you page
        $session->set('order_id', $orderId);
        $session->set('customer_data', [
            'firstName' => $checkoutDTO->firstName,
            'lastName' => $checkoutDTO->lastName,
            'companyName' => $checkoutDTO->companyName,
            'city' => $checkoutDTO->city,
            'phoneNumber' => $checkoutDTO->phoneNumber,
            'email' => $checkoutDTO->email,
        ]);

        // Check if payment is needed (total > 0)
        if ($total > 0) {
            // Get payment URL
            $returnUrl = $this->generateUrl('app_thank_you', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $checkoutResult = $this->wooCommerceService->getCheckoutUrl($orderId, $returnUrl);

            if (!$checkoutResult || !isset($checkoutResult['checkout_url'])) {
                $this->addFlash('error', 'Er is een fout opgetreden bij het aanmaken van de betaallink.');
                return $this->redirectToRoute('app_checkout');
            }

            // Redirect to WooCommerce checkout
            return $this->redirect($checkoutResult['checkout_url']);
        } else {
            // No payment needed - mark order as completed to trigger webhook
            $statusUpdated = $this->wooCommerceService->updateOrderStatus($orderId, 'completed');

            if (!$statusUpdated) {
                $this->logger->warning('Failed to update order status to completed for zero-value order', [
                    'order_id' => $orderId
                ]);
            }

            // Redirect directly to thank you page
            $this->addFlash('success', 'Je bestelling is geplaatst! Geen betaling vereist.');
            return $this->redirectToRoute('app_thank_you');
        }
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
        $orderId = $session->get('order_id');
        $customerData = $session->get('customer_data');

        // Get order_id and key from query parameters if provided by Mollie redirect
        $mollieOrderId = $request->query->get('order_id');
        $orderKey = $request->query->get('key');

        if ($mollieOrderId) {
            $orderId = $mollieOrderId;
        }

        // If we have order_id from query params, we must validate the order key
        if ($mollieOrderId && !$orderKey) {
            return $this->handleAccessError('missing_key', 'Order key is required');
        }

        $paymentStatus = null;
        $paymentInfo = null;
        $orderData = null;

        // If we have an order ID, try to get payment status
        if ($orderId) {
            // Get order details from WooCommerce
            $orderData = $this->wooCommerceService->getOrder((int) $orderId);

            if (!$orderData) {
                return $this->handleAccessError('order_not_found', 'Order not found');
            }

            // Validate order key if provided via query parameters
            if ($mollieOrderId && $orderKey) {
                $expectedOrderKey = $this->extractOrderKey($orderData);

                if (!$expectedOrderKey || $orderKey !== $expectedOrderKey) {
                    return $this->handleAccessError('invalid_key', 'Invalid order key');
                }
            }

            // Extract Mollie payment ID from order
            $molliePaymentId = $this->wooCommerceService->getMolliePaymentId($orderData);

            if ($molliePaymentId) {
                // Get payment status from Mollie
                $paymentResult = $this->mollieService->getPaymentStatus($molliePaymentId);

                if ($paymentResult['success']) {
                    $paymentStatus = $paymentResult['status'];
                    $paymentInfo = [
                        'amount' => $paymentResult['amount'],
                        'method' => $paymentResult['method'],
                        'paid_at' => $paymentResult['paid_at'],
                        'status_message' => $this->mollieService->getStatusMessage($paymentStatus),
                        'is_successful' => $this->mollieService->isPaymentSuccessful($paymentStatus),
                        'is_pending' => $this->mollieService->isPaymentPending($paymentStatus),
                        'is_failed' => $this->mollieService->isPaymentFailed($paymentStatus)
                    ];
                }
            }
        }

        // Clear cart and order data from session after successful completion
        $session->remove('cart_items');
        $session->remove('order_id');
        $session->remove('event_data');
        $session->remove('applied_coupon');
        $session->remove('subtotal');
        $session->remove('discount');
        $session->remove('tax');
        $session->remove('total');
        $session->remove('customer_data');

        return $this->render('ticketing/thank-you.html.twig', [
            'order_id' => $orderId,
            'customer_data' => $customerData,
            'payment_status' => $paymentStatus,
            'payment_info' => $paymentInfo,
        ]);
    }

    /**
     * Extract order key from WooCommerce order data
     */
    private function extractOrderKey(array $orderData): ?string
    {
        // Check direct order_key field first
        if (isset($orderData['order_key']) && !empty($orderData['order_key'])) {
            return $orderData['order_key'];
        }

        // Check in meta_data for order key
        $metaData = $orderData['meta_data'] ?? [];
        foreach ($metaData as $meta) {
            if (
                isset($meta['key'], $meta['value']) &&
                in_array($meta['key'], ['_order_key', 'order_key', '_woocommerce_order_key'])
            ) {
                return $meta['value'];
            }
        }

        return null;
    }

    /**
     * Handle access errors with configurable error pages
     */
    private function handleAccessError(string $errorCode, string $message, int $statusCode = 403): Response
    {
        $this->logger->warning('Access denied to thank you page', [
            'error_code' => $errorCode,
            'message' => $message,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);

        // Different error handling based on error code
        switch ($errorCode) {
            case 'missing_key':
            case 'invalid_key':
            case 'order_not_found':
                return $this->render('error/access_denied.html.twig', [
                    'error_code' => $errorCode,
                    'message' => $message,
                    'title' => 'Toegang Geweigerd'
                ], new Response('', $statusCode));

            default:
                return $this->render('error/general_error.html.twig', [
                    'error_code' => $errorCode,
                    'message' => $message,
                    'title' => 'Er is een fout opgetreden'
                ], new Response('', 500));
        }
    }
}
