<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\AvailableEventResponse;
use App\DTO\CouponResponse;
use App\DTO\ValidateCouponRequest;
use App\Entity\Event;
use App\Repository\CouponRepository;
use App\Repository\EventRepository;
use App\Service\DiscountCalculator;
use App\Service\MoneyService;
use App\Service\OrderPricingService;
use Money\Money;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
        private CouponRepository $couponRepository,
        private OrderPricingService $orderPricingService,
        private DiscountCalculator $discountCalculator,
        private MoneyService $moneyService,
        private ClockInterface $clock,
        private float $taxRatePercent,
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
        $session = $httpRequest->getSession();
        $cartGross = $this->cartGross($session->get('cart_items', []));

        $coupon = $this->couponRepository->findOneByCode($request->code);
        if ($coupon === null || !$this->discountCalculator->isApplicable($coupon, $this->clock->now())) {
            return $this->json(CouponResponse::invalid('Coupon is ongeldig of verlopen.'));
        }

        $totals = $this->orderPricingService->calculateFromCartGross($cartGross, $coupon, $this->taxRatePercent);

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

        $cartGross = $this->cartGross($session->get('cart_items', []));

        return $this->json(new CouponResponse(
            valid: true,
            discountCents: 0,
            newTotalCents: (int) $cartGross->getAmount(),
            message: 'Coupon removed successfully',
        ));
    }

    /**
     * @param array<array{total?: float|int}> $cartItems
     */
    private function cartGross(array $cartItems): Money
    {
        $total = Money::EUR(0);
        foreach ($cartItems as $item) {
            $total = $total->add($this->moneyService->eurosToMoney((float) ($item['total'] ?? 0)));
        }

        return $total;
    }
}
