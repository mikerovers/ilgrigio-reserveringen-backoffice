<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\OrderNote;
use App\Entity\SharedStock;
use App\Entity\Ticket;
use App\Entity\TicketType;
use App\Enum\DiscountType;
use App\Enum\OrderStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Money\Money;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // --- Coupon ---
        $coupon = new Coupon('SAVE10', DiscountType::FIXED_CART, 1000);
        $coupon->description = 'Save €10 on your order';
        $coupon->active = true;
        $manager->persist($coupon);

        // --- Event ---
        $event = new Event('Il Grigio Dinner & Jazz', new \DateTimeImmutable('2026-09-20 19:30:00'));
        $event->description = 'Een avond vol muziek en culinaire verrassingen in het hart van Amsterdam.';
        $event->location = 'Restaurant Il Grigio, Amsterdam';

        $sharedStock = new SharedStock('Il Grigio Dinner & Jazz — totaalcapaciteit');
        $sharedStock->stockQuantity = 100;
        $manager->persist($sharedStock);

        $typeStandard = new TicketType($event, 'Standaard', Money::EUR(4500));
        $typeStandard->description = 'Toegang + welkomstdrankje';
        $typeStandard->sharedStock = $sharedStock;
        $typeStandard->sortOrder = 1;

        $typeVip = new TicketType($event, 'VIP', Money::EUR(8500));
        $typeVip->description = 'Toegang + VIP-loungetoegang + champagne';
        $typeVip->sharedStock = $sharedStock;
        $typeVip->sortOrder = 2;

        $typeWheelchair = new TicketType($event, 'Rolstoel', Money::EUR(4500));
        $typeWheelchair->description = 'Toegang voor rolstoelgebruiker + begeleider';
        $typeWheelchair->sharedStock = $sharedStock;
        $typeWheelchair->sortOrder = 3;
        $typeWheelchair->disabled = true;
        $typeWheelchair->disabledReason = 'Rolstoelplaatsen en begeleiders kunnen alleen telefonisch gereserveerd worden. Klik <a href="https://ilgrigio.nl/reserveren" target="_blank" rel="noopener noreferrer" class="text-red-600 hover:text-red-700 underline">hier</a> voor meer informatie.';

        $typeChild = new TicketType($event, 'Kind (t/m 12 jaar)', Money::EUR(2500));
        $typeChild->description = 'Toegang voor kinderen tot en met 12 jaar';
        $typeChild->sharedStock = $sharedStock;
        $typeChild->sortOrder = 4;

        $typeSenior = new TicketType($event, 'Senior (65+)', Money::EUR(3500));
        $typeSenior->description = 'Toegang voor senioren vanaf 65 jaar';
        $typeSenior->sharedStock = $sharedStock;
        $typeSenior->sortOrder = 5;

        $typeStudent = new TicketType($event, 'Student', Money::EUR(3000));
        $typeStudent->description = 'Toegang voor studenten (geldig studentenbewijs vereist)';
        $typeStudent->sharedStock = $sharedStock;
        $typeStudent->sortOrder = 6;

        $event->ticketTypes->add($typeStandard);
        $event->ticketTypes->add($typeVip);
        $event->ticketTypes->add($typeWheelchair);
        $event->ticketTypes->add($typeChild);
        $event->ticketTypes->add($typeSenior);
        $event->ticketTypes->add($typeStudent);

        $manager->persist($event);

        // --- Customer ---
        $customer = new Customer('jan.devries@example.com', 'Jan', 'de Vries');
        $customer->city = 'Amsterdam';
        $customer->phoneNumber = '+31612345678';

        $manager->persist($customer);

        // --- Order (2× Standaard tickets) ---
        $taxRateBp = 900; // 9%
        $order = new Order('FIX-00001', $customer, $taxRateBp);

        $unitGross = Money::EUR(4500);
        $unitNet = Money::EUR(4128); // 4500 / 1.09 rounded
        $qty = 2;
        $lineGross = $unitGross->multiply($qty);
        $lineNet = $unitNet->multiply($qty);
        $lineTax = $lineGross->subtract($lineNet);

        $lineItem = new OrderLineItem(
            $order,
            $typeStandard,
            $typeStandard->name,
            $qty,
            $unitNet,
            $unitGross,
            $lineNet,
            $lineTax,
            $lineGross,
        );

        $order->lineItems->add($lineItem);
        $order->subtotalNet = $lineNet;
        $order->tax = $lineTax;
        $order->discountGross = Money::EUR(0);
        $order->discountTax = Money::EUR(0);
        $order->totalGross = $lineGross;
        $order->status = OrderStatus::COMPLETED;
        $order->billing->firstName = 'Jan';
        $order->billing->lastName = 'de Vries';
        $order->billing->email = 'jan.devries@example.com';
        $order->billing->city = 'Amsterdam';
        $order->shipping->firstName = 'Jan';
        $order->shipping->lastName = 'de Vries';
        $order->shipping->email = 'jan.devries@example.com';
        $order->shipping->city = 'Amsterdam';

        $now = new \DateTimeImmutable();
        $order->completeNow($now);

        $manager->persist($order);
        $manager->persist($lineItem);

        // --- Tickets ---
        for ($i = 1; $i <= $qty; $i++) {
            $ticket = new Ticket(
                sprintf('FIX-TKT-%04d', $i),
                $order,
                $lineItem,
                $typeStandard,
                $event,
                $typeStandard->name,
                $event->name,
                $event->startsAt,
                $now,
            );
            $order->tickets->add($ticket);
            $manager->persist($ticket);
        }

        // --- Note ---
        $note = new OrderNote($order, 'Fixture: bestelling aangemaakt voor testdoeleinden.');
        $order->notes->add($note);
        $manager->persist($note);

        $manager->flush();
    }
}
