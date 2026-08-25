<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\TicketType;
use App\Enum\StockStatus;
use App\Event\OrderCreatedEvent;
use App\Repository\CustomerRepository;
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

        // Validate stock before any mutation.
        // Accumulate demand per shared pool so cross-type totals are checked together.
        /** @var array<string, int> $sharedDemand keyed by SharedStock id string */
        $sharedDemand = [];

        foreach ($cart as $row) {
            $ticketType = $row['ticketType'];
            $quantity = $row['quantity'];

            if ($ticketType->disabled) {
                throw new \RuntimeException(sprintf('Ticket type "%s" is not available for purchase.', $ticketType->name));
            }

            if ($ticketType->sharedStock !== null) {
                $poolId = (string) $ticketType->sharedStock->id;
                $sharedDemand[$poolId] = ($sharedDemand[$poolId] ?? 0) + $quantity;
            }
        }

        // Validate shared pool totals.
        foreach ($cart as $row) {
            $sharedStock = $row['ticketType']->sharedStock;
            if ($sharedStock === null) {
                continue;
            }

            $poolId = (string) $sharedStock->id;
            if ($sharedStock->stockStatus === StockStatus::OUT_OF_STOCK) {
                throw new \RuntimeException(sprintf('Shared stock pool "%s" is out of stock.', $sharedStock->name));
            }

            if ($sharedStock->stockQuantity !== null && $sharedStock->stockQuantity < ($sharedDemand[$poolId] ?? 0)) {
                throw new \RuntimeException(sprintf(
                    'Only %d ticket(s) remaining in pool "%s".',
                    $sharedStock->stockQuantity,
                    $sharedStock->name,
                ));
            }
        }

        $event = $cart[0]['ticketType']->event ?? null;
        if ($event !== null && $event->stockStatus() === StockStatus::OUT_OF_STOCK) {
            throw new \RuntimeException('This event is sold out.');
        }

        /** @var array<string, true> $decrementedPools tracks which SharedStock pools have been decremented */
        $decrementedPools = [];

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

            if ($ticketType->sharedStock !== null) {
                $poolId = (string) $ticketType->sharedStock->id;
                if (!isset($decrementedPools[$poolId])) {
                    $ticketType->sharedStock->decrementStock($sharedDemand[$poolId]);
                    $decrementedPools[$poolId] = true;
                }
            }
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

        // Dispatch before flush so the history listener's note is included in the same flush.
        $this->eventDispatcher->dispatch(new OrderCreatedEvent($order));

        $this->entityManager->flush();

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
