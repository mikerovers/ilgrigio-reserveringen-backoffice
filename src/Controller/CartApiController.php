<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AvailableEventResponse;
use App\DTO\CouponResponse;
use App\DTO\ValidateCouponRequest;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\CouponResolver;
use App\Service\MoneyService;
use App\Service\OrderPricingService;
use Money\Money;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API for the cart/coupon flow. Kept separate from the templating
 * TicketingController so HTML and API concerns don't share a controller.
 */
#[Route('/api', name: 'api_cart_')]
final class CartApiController extends AbstractController
{
    public function __construct(
        private EventRepository $eventRepository,
        private CouponResolver $couponResolver,
        private OrderPricingService $orderPricingService,
        private MoneyService $moneyService,
        private ClockInterface $clock,
        private float $taxRatePercent,
        #[Autowire(service: 'limiter.validate_coupon')]
        private RateLimiterFactory $validateCouponLimiter,
    ) {
    }

    #[Route('/available-events', name: 'available_events', methods: ['GET'])]
    public function availableEvents(): JsonResponse
    {
        $events = array_map(
            fn (Event $e) => AvailableEventResponse::fromEntity($e),
            $this->eventRepository->findUpcoming($this->clock->now()),
        );

        return $this->json($events);
    }

    #[Route('/validate-coupon', name: 'validate_coupon', methods: ['POST'])]
    public function validateCoupon(
        #[MapRequestPayload] ValidateCouponRequest $request,
        Request $httpRequest,
    ): JsonResponse {
        $limit = $this->validateCouponLimiter->create($httpRequest->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->json(
                CouponResponse::invalid('Te veel pogingen, probeer het later opnieuw.'),
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $limit->getRetryAfter()->getTimestamp()],
            );
        }

        $session = $httpRequest->getSession();
        $ticketRows = $this->ticketRows($session->get('cart_items', []), $session->get('event_slug'));

        $coupon = $this->couponResolver->resolve($request->code);
        if ($coupon === null) {
            return $this->json(CouponResponse::invalid('Coupon is ongeldig, verlopen of al gebruikt.'));
        }

        $totals = $this->orderPricingService->calculateFromTicketRows($ticketRows, $coupon, $this->taxRatePercent);

        $session->set('applied_coupon_code', $coupon->code);
        $session->set('applied_coupon', ['code' => $coupon->code]);
        $session->set('discount', $this->moneyService->toFloat($totals->discountGross));

        return $this->json(new CouponResponse(
            valid: true,
            code: $coupon->code,
            discountType: $coupon->discountType,
            amount: $coupon->amount,
            discountCents: (int) $totals->discountGross->getAmount(),
            newTotalCents: (int) $totals->totalGross->getAmount(),
        ));
    }

    #[Route('/remove-coupon', name: 'remove_coupon', methods: ['POST'])]
    public function removeCoupon(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $session->remove('applied_coupon_code');
        $session->remove('applied_coupon');
        $session->set('discount', 0);

        $ticketRows = $this->ticketRows($session->get('cart_items', []), $session->get('event_slug'));
        $cartGross = $this->orderPricingService
            ->calculateFromTicketRows($ticketRows, null, $this->taxRatePercent)
            ->subtotalGross;

        return $this->json(new CouponResponse(
            valid: true,
            discountCents: 0,
            newTotalCents: (int) $cartGross->getAmount(),
            message: 'Coupon removed successfully',
        ));
    }

    /**
     * @param array<array{id?: string, price?: float|int, quantity?: int}> $cartItems
     * @return array<array{price: Money, quantity: int}>
     */
    private function ticketRows(array $cartItems, mixed $eventSlug): array
    {
        $ticketTypesById = [];
        if (is_string($eventSlug)) {
            $event = $this->eventRepository->findOneBySlug($eventSlug);
            foreach ($event?->ticketTypes ?? [] as $ticketType) {
                $ticketTypesById[(string) $ticketType->id] = $ticketType;
            }
        }

        $rows = [];
        foreach ($cartItems as $item) {
            $ticketType = $ticketTypesById[(string) ($item['id'] ?? '')] ?? null;
            $price = $ticketType?->price ?? $this->moneyService->eurosToMoney((float) ($item['price'] ?? 0));
            $rows[] = ['price' => $price, 'quantity' => (int) ($item['quantity'] ?? 0)];
        }

        return $rows;
    }
}
