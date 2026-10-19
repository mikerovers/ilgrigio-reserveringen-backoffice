<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CreateOrderRequest;
use App\DTO\Discount;
use App\DTO\RegisterOrderResult;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\TicketType;
use App\Enum\OrderSource;
use App\Enum\PaymentStatus;
use App\Enum\RegisterDiscountType;
use App\Event\OrderCompletedEvent;
use App\Event\PaymentStatusChangedEvent;
use App\Exception\RegisterOrderException;
use App\Repository\OrderRepository;
use App\Repository\TicketTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates an order on behalf of the register (POS): validates the request,
 * reuses CheckoutService for pricing/stock, then settles it immediately as a
 * cash or pin payment and fans out fulfilment. Safe to retry via an idempotency key.
 */
final class RegisterOrderService
{
    public const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    public function __construct(
        private CheckoutService $checkoutService,
        private CouponResolver $couponResolver,
        private TicketTypeRepository $ticketTypeRepository,
        private OrderRepository $orderRepository,
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private int $maxTicketsPerOrder,
        private float $maxDiscountPercent = 100.0,
    ) {
    }

    /**
     * @throws RegisterOrderException for unknown ticket types or invalid input
     * @throws \RuntimeException      when stock/sales rules or the coupon refuse the order (409)
     */
    public function create(CreateOrderRequest $request, ?string $idempotencyKey): RegisterOrderResult
    {
        $idempotencyKey = $idempotencyKey === null || trim($idempotencyKey) === '' ? null : trim($idempotencyKey);
        if ($idempotencyKey !== null && strlen($idempotencyKey) > self::IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw new RegisterOrderException('Idempotency-Key may not exceed 64 characters.', 422);
        }

        if ($idempotencyKey === null) {
            return $this->createOrder($request, null);
        }

        $existing = $this->orderRepository->findOneByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return new RegisterOrderResult($existing, false);
        }

        // Serialise concurrent retries of the same key; the unique index is the backstop.
        $lock = $this->lockFactory->createLock('register-order-' . hash('sha256', $idempotencyKey), 30);
        $lock->acquire(true);

        try {
            $existing = $this->orderRepository->findOneByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return new RegisterOrderResult($existing, false);
            }

            return $this->createOrder($request, $idempotencyKey);
        } finally {
            $lock->release();
        }
    }

    private function createOrder(CreateOrderRequest $request, ?string $idempotencyKey): RegisterOrderResult
    {
        $cart = $this->buildCart($request);

        $couponCode = $request->couponCode === null ? '' : trim($request->couponCode);
        if ($couponCode !== '' && $request->discount !== null) {
            throw new RegisterOrderException('A coupon code and a manual discount cannot be combined.', 422);
        }

        $coupon = null;
        if ($couponCode !== '') {
            $coupon = $this->couponResolver->resolve($couponCode);
            if ($coupon === null) {
                throw new RegisterOrderException(sprintf('Coupon "%s" is invalid or no longer available.', $couponCode), 422);
            }
        }

        $manualDiscount = $this->buildManualDiscount($request, $cart);
        $now = $this->clock->now();
        $customerRequest = $request->customer;

        $customer = $this->checkoutService->resolveCustomer(
            trim($customerRequest->email),
            trim($customerRequest->firstName),
            trim($customerRequest->lastName),
            $customerRequest->companyName ?: null,
            $customerRequest->city ?: null,
            $customerRequest->phoneNumber ?: null,
        );

        $order = $this->checkoutService->createOrder(
            $cart,
            $customer,
            $coupon,
            $now,
            $manualDiscount,
            $manualDiscount !== null ? trim((string) $request->discount?->reason) : null,
        );

        $order->source = OrderSource::REGISTER;
        $order->idempotencyKey = $idempotencyKey;
        $this->snapshotAddresses($order, $request);

        if ($order->totalGross->isZero()) {
            $order->completeNow($now);
            $this->checkoutService->save($order);
        } else {
            $payment = new Payment($order, $order->totalGross);
            $payment->method = $request->paymentMethod->value;
            $payment->status = PaymentStatus::PAID;
            $payment->paidAt = $now;
            $payment->description = sprintf('Kassa (%s)', $request->paymentMethod->value);
            $order->payment = $payment;
            $this->entityManager->persist($payment);

            $order->completeNow($now);
            $this->checkoutService->save($order);

            $this->eventDispatcher->dispatch(new PaymentStatusChangedEvent($order, PaymentStatus::OPEN, PaymentStatus::PAID));
        }

        $this->eventDispatcher->dispatch(new OrderCompletedEvent($order));

        $this->logger->info('Register order created', [
            'order_number' => $order->orderNumber,
            'idempotency_key' => $idempotencyKey,
            'payment_method' => $order->payment?->method,
            'total_cents' => $order->totalGross->getAmount(),
        ]);

        return new RegisterOrderResult($order, true);
    }

    /**
     * @return array<array{ticketType: TicketType, quantity: int}>
     */
    private function buildCart(CreateOrderRequest $request): array
    {
        /** @var array<string, int> $quantities keyed by ticket type id */
        $quantities = [];
        foreach ($request->items as $item) {
            $id = strtolower($item->ticketTypeId);
            $quantities[$id] = ($quantities[$id] ?? 0) + $item->quantity;
        }

        if (array_sum($quantities) > $this->maxTicketsPerOrder) {
            throw new RegisterOrderException(sprintf('An order may contain at most %d tickets.', $this->maxTicketsPerOrder), 422);
        }

        $cart = [];
        foreach ($quantities as $id => $quantity) {
            $ticketType = $this->ticketTypeRepository->find($id);
            if ($ticketType === null) {
                throw new RegisterOrderException(sprintf('Ticket type %s could not be found.', $id), 404);
            }

            $cart[] = ['ticketType' => $ticketType, 'quantity' => $quantity];
        }

        return $cart;
    }

    /**
     * @param array<array{ticketType: TicketType, quantity: int}> $cart
     */
    private function buildManualDiscount(CreateOrderRequest $request, array $cart): ?Discount
    {
        if ($request->discount === null) {
            return null;
        }

        $discount = new Discount($request->discount->type->toDiscountType(), $request->discount->amount);

        if ($request->discount->type === RegisterDiscountType::PERCENT) {
            if ($discount->amount > (int) round($this->maxDiscountPercent * 100)) {
                throw new RegisterOrderException(
                    sprintf('A discount may not exceed %s%%.', rtrim(rtrim(number_format($this->maxDiscountPercent, 2, '.', ''), '0'), '.')),
                    422,
                );
            }

            return $discount;
        }

        $subtotal = Money::EUR(0);
        foreach ($cart as $row) {
            $subtotal = $subtotal->add($row['ticketType']->price->multiply($row['quantity']));
        }

        if (Money::EUR($discount->amount)->greaterThan($subtotal)) {
            throw new RegisterOrderException('A fixed discount cannot exceed the order subtotal.', 422);
        }

        return $discount;
    }

    private function snapshotAddresses(Order $order, CreateOrderRequest $request): void
    {
        $customer = $request->customer;

        $order->billing->firstName = trim($customer->firstName);
        $order->billing->lastName = trim($customer->lastName);
        $order->billing->companyName = $customer->companyName ?: null;
        $order->billing->city = (string) $customer->city;
        $order->billing->phoneNumber = $customer->phoneNumber ?: null;
        $order->billing->email = trim($customer->email);
        $order->shipping->firstName = $order->billing->firstName;
        $order->shipping->lastName = $order->billing->lastName;
        $order->shipping->companyName = $order->billing->companyName;
        $order->shipping->city = $order->billing->city;
    }
}
