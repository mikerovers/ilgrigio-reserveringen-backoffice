<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\SharedStock;
use App\Entity\StockReservation;
use App\Entity\TicketType;
use App\Enum\StockStatus;
use App\Event\OrderCreatedEvent;
use App\Repository\CustomerRepository;
use App\Repository\SharedStockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Assembles a persisted local Order from a validated cart, replacing the
 * WooCommerce order-creation round-trip. All prices come from the TicketType
 * entities (never trusted from the client); money is frozen as a cents snapshot.
 */
final class CheckoutService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CustomerRepository $customerRepository,
        private SharedStockRepository $sharedStockRepository,
        private MoneyService $moneyService,
        private OrderPricingService $orderPricingService,
        private OrderNumberGenerator $orderNumberGenerator,
        private EventDispatcherInterface $eventDispatcher,
        private float $taxRatePercent,
    ) {
    }

    /**
     * @param array<array{ticketType: TicketType, quantity: int}> $cart
     */
    public function createOrder(
        array $cart,
        Customer $customer,
        ?Coupon $coupon,
        \DateTimeImmutable $now,
    ): Order {
        $order = new Order(
            $this->orderNumberGenerator->generate($now),
            $customer,
            (int) round($this->taxRatePercent * 100),
        );

        // Accumulate demand per shared pool so cross-type totals are checked together.
        /** @var array<string, int> $sharedDemand keyed by SharedStock id string */
        $sharedDemand = [];
        /** @var array<string, SharedStock> $pools */
        $pools = [];

        foreach ($cart as $row) {
            $ticketType = $row['ticketType'];

            if ($ticketType->disabled) {
                throw new \RuntimeException(sprintf('Ticket type "%s" is not available for purchase.', $ticketType->name));
            }

            if ($ticketType->event->stockStatus() === StockStatus::OUT_OF_STOCK) {
                throw new \RuntimeException('This event is sold out.');
            }

            $pool = $ticketType->sharedStock;
            if ($pool === null) {
                continue;
            }

            if ($pool->stockStatus === StockStatus::OUT_OF_STOCK) {
                throw new \RuntimeException(sprintf('Shared stock pool "%s" is out of stock.', $pool->name));
            }

            $poolId = (string) $pool->id;
            $pools[$poolId] = $pool;
            $sharedDemand[$poolId] = ($sharedDemand[$poolId] ?? 0) + $row['quantity'];
        }

        // Always lock pools in the same order so concurrent checkouts cannot deadlock.
        ksort($pools);

        $connection = $this->entityManager->getConnection();
        /** @var StockReservation[] $reservations */
        $reservations = [];

        $connection->beginTransaction();

        try {
            foreach ($pools as $poolId => $pool) {
                $demand = $sharedDemand[$poolId];

                // Check and decrement in one atomic UPDATE: the only race-free guard.
                if (!$this->sharedStockRepository->reserve($pool, $demand)) {
                    $this->entityManager->refresh($pool);

                    throw new \RuntimeException(sprintf(
                        'Only %d ticket(s) remaining in pool "%s".',
                        $pool->stockQuantity ?? 0,
                        $pool->name,
                    ));
                }

                // Decrement already happened atomically in SQL above; the in-memory
                // entity is intentionally left stale (not refreshed) to avoid an
                // extra round-trip while the pool's row lock is held, and because
                // its stock fields are not read again on this path.
                $reservations[] = new StockReservation($order, $pool, $demand, $now);
            }

            foreach ($cart as $row) {
                $ticketType = $row['ticketType'];
                $quantity = $row['quantity'];

                $unitGross = $ticketType->price;
                $lineGross = $unitGross->multiply($quantity);
                [$unitNet] = $this->moneyService->splitGross($unitGross, $this->taxRatePercent);
                [$lineNet, $lineTax] = $this->moneyService->splitGross($lineGross, $this->taxRatePercent);

                $order->lineItems->add(new OrderLineItem(
                    $order,
                    $ticketType,
                    $ticketType->name,
                    $quantity,
                    $unitNet,
                    $unitGross,
                    $lineNet,
                    $lineTax,
                    $lineGross,
                ));
            }

            $totals = $this->orderPricingService->calculate($cart, $coupon, $this->taxRatePercent);

            $order->subtotalNet = $totals->subtotalNet;
            $order->tax = $totals->tax;
            $order->discountGross = $totals->discountGross;
            $order->discountTax = $totals->discountTax;
            $order->totalGross = $totals->totalGross;

            if ($coupon !== null) {
                $order->coupon = $coupon;
                $order->couponCode = $coupon->code;
            }

            $this->entityManager->persist($order);
            foreach ($reservations as $reservation) {
                $this->entityManager->persist($reservation);
            }

            // Dispatch before flush so the history listener's note is included in the same flush.
            $this->eventDispatcher->dispatch(new OrderCreatedEvent($order));

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return $order;
    }

    /**
     * Persist pending changes to an order (e.g. billing snapshot, completion).
     */
    public function save(Order $order): void
    {
        $this->entityManager->flush();
    }

    /**
     * Find an existing customer by email or create one from checkout details.
     */
    public function resolveCustomer(
        string $email,
        string $firstName,
        string $lastName,
        ?string $companyName,
        ?string $city,
        ?string $phoneNumber,
    ): Customer {
        $customer = $this->customerRepository->findOneByEmail($email);

        if ($customer === null) {
            $customer = new Customer($email, $firstName, $lastName);
            $this->entityManager->persist($customer);
        } else {
            $customer->firstName = $firstName;
            $customer->lastName = $lastName;
        }

        $customer->companyName = $companyName;
        $customer->city = $city;
        $customer->phoneNumber = $phoneNumber;

        return $customer;
    }
}
