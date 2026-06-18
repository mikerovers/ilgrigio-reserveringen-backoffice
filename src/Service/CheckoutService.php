<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\TicketType;
use App\Enum\StockStatus;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;

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
        private DiscountCalculator $discountCalculator,
        private OrderNumberGenerator $orderNumberGenerator,
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

        $grossSubtotal = Money::EUR(0);
        $netSubtotal = Money::EUR(0);
        $taxSubtotal = Money::EUR(0);
        $totalTickets = 0;

        // Validate stock before any mutation.
        foreach ($cart as $row) {
            $ticketType = $row['ticketType'];
            $quantity = $row['quantity'];

            if ($ticketType->stockStatus === StockStatus::OUT_OF_STOCK) {
                throw new \RuntimeException(sprintf('Ticket type "%s" is out of stock.', $ticketType->name));
            }

            if ($ticketType->manageStock && $ticketType->stockQuantity !== null && $ticketType->stockQuantity < $quantity) {
                throw new \RuntimeException(sprintf(
                    'Only %d ticket(s) remaining for "%s".',
                    $ticketType->stockQuantity,
                    $ticketType->name,
                ));
            }
        }

        $event = $cart[0]['ticketType']->event ?? null;
        if ($event !== null && $event->stockStatus === StockStatus::OUT_OF_STOCK) {
            throw new \RuntimeException('This event is sold out.');
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

            $ticketType->decrementStock($quantity);
            $totalTickets += $quantity;

            $grossSubtotal = $grossSubtotal->add($lineGross);
            $netSubtotal = $netSubtotal->add($lineNet);
            $taxSubtotal = $taxSubtotal->add($lineTax);
        }

        if ($event !== null) {
            $event->decrementStock($totalTickets);
        }

        $discountGross = Money::EUR(0);
        $discountNet = Money::EUR(0);
        $discountTax = Money::EUR(0);

        if ($coupon !== null) {
            $discountGross = $this->discountCalculator->discountGross($coupon, $grossSubtotal);
            [$discountNet, $discountTax] = $this->discountCalculator->splitDiscount(
                $discountGross,
                $this->taxRatePercent,
            );
            $order->coupon = $coupon;
            $order->couponCode = $coupon->code;
        }

        // Store post-discount components so subtotalNet + tax == totalGross always.
        $order->subtotalNet = $netSubtotal->subtract($discountNet);
        $order->tax = $taxSubtotal->subtract($discountTax);
        $order->discountGross = $discountGross;
        $order->discountTax = $discountTax;
        $order->totalGross = $grossSubtotal->subtract($discountGross);

        $this->entityManager->persist($order);
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
