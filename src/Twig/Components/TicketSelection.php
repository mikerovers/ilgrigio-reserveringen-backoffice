<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\TicketSelectionResult;
use App\Entity\Coupon;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\CouponResolver;
use App\Service\MoneyService;
use App\Service\OrderPricingService;
use App\Service\TicketSelectionValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Server-side validation and coupon handling for the ticket selection form.
 *
 * Owns only the coupon block and the JSON state the Stimulus controller reads.
 * Steppers, the item list and the no-coupon totals stay in the browser; the
 * quantities are passed in with every action rather than kept as LiveProps.
 */
#[AsLiveComponent(name: 'TicketSelection', template: 'components/ticket_selection.html.twig')]
final class TicketSelection
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $eventSlug = '';

    #[LiveProp]
    public ?string $appliedCouponCode = null;

    #[LiveProp]
    public string $couponInput = '';

    #[LiveProp]
    public ?string $couponError = null;

    /**
     * JSON-serialisable state read by the Stimulus controller after each action.
     *
     * @var array<string, mixed>
     */
    #[LiveProp]
    public array $state = [];

    public function __construct(
        private EventRepository $eventRepository,
        private CouponResolver $couponResolver,
        private OrderPricingService $orderPricingService,
        private TicketSelectionValidator $ticketSelectionValidator,
        private MoneyService $moneyService,
        private RequestStack $requestStack,
        #[Autowire(env: 'float:TAX_RATE')]
        private float $taxRate,
        #[Autowire(service: 'limiter.validate_coupon')]
        private RateLimiterFactory $couponLimiter,
    ) {
    }

    #[LiveAction]
    public function applyCoupon(#[LiveArg] string $code, #[LiveArg] string $tickets): void
    {
        $code = trim($code);
        $this->couponInput = $code;
        $this->couponError = null;
        $quantities = $this->decodeQuantities($tickets);
        $event = $this->event();

        if ($code === '') {
            $this->couponError = 'Voer een kortingscode in.';
        } elseif (!$this->couponLimiter->create($this->clientIp())->consume()->isAccepted()) {
            $this->couponError = 'Te veel pogingen, probeer het later opnieuw.';
        } else {
            $coupon = $this->couponResolver->resolve($code);
            if ($coupon === null) {
                $this->couponError = 'Kortingscode is ongeldig, verlopen of al gebruikt.';
            } else {
                $this->appliedCouponCode = $coupon->code;
                $this->couponInput = '';
            }
        }

        $this->refreshState($event, $quantities, $this->ticketSelectionValidator->validate($event, $quantities));
    }

    #[LiveAction]
    public function removeCoupon(#[LiveArg] string $tickets): void
    {
        $this->appliedCouponCode = null;
        $this->couponInput = '';
        $this->couponError = null;
        $quantities = $this->decodeQuantities($tickets);
        $event = $this->event();

        $this->refreshState($event, $quantities, $this->ticketSelectionValidator->validate($event, $quantities));
    }

    /**
     * Recalculates the discount and totals for the current selection. Called
     * (debounced) by the browser after quantity changes while a coupon is applied.
     */
    #[LiveAction]
    public function refreshTotals(#[LiveArg] string $tickets): void
    {
        $quantities = $this->decodeQuantities($tickets);
        $event = $this->event();

        $this->refreshState($event, $quantities, $this->ticketSelectionValidator->validate($event, $quantities));
    }

    private function refreshState(Event $event, array $quantities, TicketSelectionResult $result): void
    {
        $coupon = $this->activeCoupon();
        $totals = $this->orderPricingService->calculate(
            $this->ticketSelectionValidator->cartFor($event, $quantities),
            $coupon,
            $this->taxRate,
        );

        $this->syncSession($coupon, $this->moneyService->toFloat($totals->discountGross));

        $this->state = [
            'valid' => $result->isValid(),
            'rowErrors' => $result->rowErrors,
            'formErrors' => $result->formErrors,
            'couponCode' => $coupon?->code,
            'totals' => [
                'totalTickets' => $result->totalQuantity,
                'subtotalNet' => $this->moneyService->toFloat($totals->subtotalNet),
                'tax' => $this->moneyService->toFloat($totals->tax),
                'totalGross' => $this->moneyService->toFloat($totals->totalGross),
                'discountGross' => $this->moneyService->toFloat($totals->discountGross),
            ],
        ];
    }

    /**
     * Resolves the applied coupon. If it stopped being valid (expired, used up),
     * it is dropped here so the customer sees the change instead of a silent full price.
     */
    private function activeCoupon(): ?Coupon
    {
        if ($this->appliedCouponCode === null) {
            return null;
        }

        $coupon = $this->couponResolver->resolve($this->appliedCouponCode);
        if ($coupon === null) {
            $this->appliedCouponCode = null;
            $this->couponError = 'Je kortingscode is niet meer geldig en is verwijderd.';
        }

        return $coupon;
    }

    /**
     * Keeps the session in the shape processTicketOrder() and checkout read.
     */
    private function syncSession(?Coupon $coupon, float $discount): void
    {
        $session = $this->requestStack->getSession();

        if ($coupon === null) {
            $session->remove('applied_coupon_code');
            $session->remove('applied_coupon');
            $session->set('discount', 0);

            return;
        }

        $session->set('applied_coupon_code', $coupon->code);
        $session->set('applied_coupon', ['code' => $coupon->code]);
        $session->set('discount', $discount);
    }

    private function event(): Event
    {
        $event = $this->eventRepository->findOneBySlug($this->eventSlug);
        if ($event === null) {
            throw new NotFoundHttpException('Show not found.');
        }

        return $event;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeQuantities(string $tickets): array
    {
        $decoded = json_decode($tickets, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function clientIp(): string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'unknown';
    }
}
