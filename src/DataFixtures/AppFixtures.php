<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\EventCategory;
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
        $event = new Event('Biblio Magico', new \DateTimeImmutable('2027-06-31 14:00:00'));
        $event->description = 'Dit jaar presenteerden wij onze show Biblio Magico! De Bibliothecaresse en haar assistent probeerden uit alle macht hun bibliotheek netjes op orde te houden. Dat bleek niet makkelijk met een Magisch Boek dat verhalen tot leven bracht.';
        $event->location = 'Bossebaan 5B, Haaren NB.';

        $sharedStock = new SharedStock('Il Grigio Biblio Magico 2026-06-31 — totaalcapaciteit');
        $sharedStock->stockQuantity = 740;
        $manager->persist($sharedStock);

        $typeStandardAdults = new TicketType($event, 'vanaf 13 jaar (incl volwassenen)', Money::EUR(1200));
        $typeStandardAdults->description = 'vanaf 13 jaar (incl volwassenen)';
        $typeStandardAdults->sharedStock = $sharedStock;
        $typeStandardAdults->sortOrder = 1;
        $manager->persist($typeStandardAdults);

        $typeStandardChildren = new TicketType($event, '3 t/m 12 jaar', Money::EUR(1000));
        $typeStandardChildren->description = '3 t/m 12 jaar';
        $typeStandardChildren->sharedStock = $sharedStock;
        $typeStandardChildren->sortOrder = 0;
        $manager->persist($typeStandardChildren);

        $typeStandardInfants = new TicketType($event, 't/m 2 jaar', Money::EUR(0));
        $typeStandardInfants->description = 't/m 2 jaar';
        $typeStandardInfants->sharedStock = $sharedStock;
        $typeStandardInfants->sortOrder = 2;
        $manager->persist($typeStandardInfants);

        $sharedStockWheelchair = new TicketType($event, 'Rolstoel', Money::EUR(1200));
        $sharedStockWheelchair->description = 'Toegang voor rolstoelgebruiker';
        $sharedStockWheelchair->sharedStock = $sharedStock;
        $sharedStockWheelchair->sortOrder = 3;
        $sharedStockWheelchair->disabled = true;
        $sharedStockWheelchair->disabledReason = 'Rolstoelplaatsen en begeleiders kunnen alleen telefonisch gereserveerd worden. Klik <a href="https://ilgrigio.nl/reserveren" target="_blank" rel="noopener noreferrer" class="text-red-600 hover:text-red-700 underline">hier</a> voor meer informatie.';
        $manager->persist($sharedStockWheelchair);

        $event->ticketTypes->add($typeStandardAdults);
        $event->ticketTypes->add($typeStandardChildren);
        $event->ticketTypes->add($sharedStockWheelchair);

        $category = new EventCategory('Theater');
        $manager->persist($category);
        $event->categories->add($category);
        $category->events->add($event);

        $manager->persist($event);

        // Temp disable the order fixture
        // // --- Customer ---
        // $customer = new Customer('jan.devries@example.com', 'Jan', 'de Vries');
        // $customer->city = 'Amsterdam';
        // $customer->phoneNumber = '+31612345678';

        // $manager->persist($customer);

        // // --- Order (2× Standaard tickets) ---
        // $taxRateBp = 900; // 9%
        // $order = new Order('FIX-00001', $customer, $taxRateBp);

        // $unitGross = Money::EUR(4500);
        // $unitNet = Money::EUR(4128); // 4500 / 1.09 rounded
        // $qty = 2;
        // $lineGross = $unitGross->multiply($qty);
        // $lineNet = $unitNet->multiply($qty);
        // $lineTax = $lineGross->subtract($lineNet);

        // $lineItem = new OrderLineItem(
        //     $order,
        //     $typeStandard,
        //     $typeStandard->name,
        //     $qty,
        //     $unitNet,
        //     $unitGross,
        //     $lineNet,
        //     $lineTax,
        //     $lineGross,
        // );

        // $order->lineItems->add($lineItem);
        // $order->subtotalNet = $lineNet;
        // $order->tax = $lineTax;
        // $order->discountGross = Money::EUR(0);
        // $order->discountTax = Money::EUR(0);
        // $order->totalGross = $lineGross;
        // $order->status = OrderStatus::COMPLETED;
        // $order->billing->firstName = 'Jan';
        // $order->billing->lastName = 'de Vries';
        // $order->billing->email = 'jan.devries@example.com';
        // $order->billing->city = 'Amsterdam';
        // $order->shipping->firstName = 'Jan';
        // $order->shipping->lastName = 'de Vries';
        // $order->shipping->email = 'jan.devries@example.com';
        // $order->shipping->city = 'Amsterdam';

        // $now = new \DateTimeImmutable();
        // $order->completeNow($now);

        // $manager->persist($order);
        // $manager->persist($lineItem);

        // // --- Tickets ---
        // for ($i = 1; $i <= $qty; $i++) {
        //     $ticket = new Ticket(
        //         sprintf('FIX-TKT-%04d', $i),
        //         $order,
        //         $lineItem,
        //         $typeStandard,
        //         $event,
        //         $typeStandard->name,
        //         $event->name,
        //         $event->startsAt,
        //         $now,
        //     );
        //     $order->tickets->add($ticket);
        //     $manager->persist($ticket);
        // }

        // // --- Note ---
        // $note = new OrderNote($order, 'Fixture: bestelling aangemaakt voor testdoeleinden.');
        // $order->notes->add($note);
        // $manager->persist($note);

        $manager->flush();
    }
}
