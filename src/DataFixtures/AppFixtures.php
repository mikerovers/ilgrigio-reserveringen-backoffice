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
use App\Service\VenueTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Money\Money;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // --- Coupon ---
        $coupon = new Coupon('SAVE10', DiscountType::FIXED_CART, 1200);
        $coupon->description = 'Vrijkaartje Camping';
        $coupon->active = true;
        $manager->persist($coupon);

        // --- Event ---
        $event = new Event('Biblio Magico', VenueTime::fromLocal('2027-06-31 14:00:00'));
        $event->description = 'Dit jaar presenteerden wij onze show Biblio Magico! De Bibliothecaresse en haar assistent probeerden uit alle macht hun bibliotheek netjes op orde te houden. Dat bleek niet makkelijk met een Magisch Boek dat verhalen tot leven bracht.';
        $event->location = 'Bossebaan 5B, Haaren NB.';

        $sharedStockRegular = new SharedStock('Il Grigio Biblio Magico 2026-06-31 — totaalcapaciteit');
        $sharedStockRegular->stockQuantity = 740;
        $manager->persist($sharedStockRegular);
        $event->displayStock = $sharedStockRegular;

        $sharedStockWheelchair = new SharedStock('Il Grigio Biblio Magico 2026-06-31 — rolstoel');
        $sharedStockWheelchair->stockQuantity = 4;
        $manager->persist($sharedStockWheelchair);

        $typeStandardAdults = new TicketType($event, 'vanaf 13 jaar (incl volwassenen)', Money::EUR(1200));
        $typeStandardAdults->description = 'vanaf 13 jaar (incl volwassenen)';
        $typeStandardAdults->sharedStock = $sharedStockRegular;
        $typeStandardAdults->sortOrder = 1;
        $manager->persist($typeStandardAdults);

        $typeStandardChildren = new TicketType($event, '3 t/m 12 jaar', Money::EUR(1000));
        $typeStandardChildren->description = '3 t/m 12 jaar';
        $typeStandardChildren->sharedStock = $sharedStockRegular;
        $typeStandardChildren->sortOrder = 0;
        $manager->persist($typeStandardChildren);

        $typeStandardInfants = new TicketType($event, 't/m 2 jaar', Money::EUR(0));
        $typeStandardInfants->description = 't/m 2 jaar';
        $typeStandardInfants->sharedStock = $sharedStockRegular;
        $typeStandardInfants->sortOrder = 2;
        $manager->persist($typeStandardInfants);

        $sharedStockWheelchair = new TicketType($event, 'Rolstoel', Money::EUR(1200));
        $sharedStockWheelchair->description = 'Toegang voor rolstoelgebruiker';
        $sharedStockWheelchair->sharedStock = $sharedStockRegular;
        $sharedStockWheelchair->sortOrder = 3;
        $sharedStockWheelchair->disabled = true;
        $sharedStockWheelchair->disabledReason = 'Rolstoelplaatsen en begeleiders kunnen alleen telefonisch gereserveerd worden. Klik <a href="https://ilgrigio.nl/reserveren" target="_blank" rel="noopener noreferrer" class="text-red-600 hover:text-red-700 underline">hier</a> voor meer informatie.';
        $manager->persist($sharedStockWheelchair);

        $event->ticketTypes->add($typeStandardAdults);
        $event->ticketTypes->add($typeStandardChildren);
        $event->ticketTypes->add($sharedStockWheelchair);

        $category = new EventCategory('Bilbio Magico');
        $manager->persist($category);
        $event->categories->add($category);
        $category->events->add($event);

        $manager->persist($event);

        $manager->flush();
    }
}
