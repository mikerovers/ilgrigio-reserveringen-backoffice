<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\Event;
use App\Entity\Order;
use App\Entity\EventCategory;
use App\Entity\SharedStock;
use App\Entity\TicketType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RegisterEventPickerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const USER_EMAIL = 'register-picker-user@example.com';
    private const PREFIX = 'RPTEST';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private EventCategory $category;
    private EventCategory $other;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) ($_SERVER['REGISTER_HOST'] ?? 'register.localhost');
        $this->client = static::createClient(server: ['HTTP_HOST' => $host]);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $this->user = new User(self::USER_EMAIL, 'unused', ['ROLE_REGISTER']);
        $this->user->name = 'Kassa Test';
        $this->em->persist($this->user);

        $this->category = new EventCategory(self::PREFIX . ' Magico');
        $this->other = new EventCategory(self::PREFIX . ' Other');
        $this->em->persist($this->category);
        $this->em->persist($this->other);

        $soon = new Event(self::PREFIX . ' Soon', new \DateTimeImmutable('+10 days'));
        $later = new Event(self::PREFIX . ' Later', new \DateTimeImmutable('+20 days'));
        $past = new Event(self::PREFIX . ' Past', new \DateTimeImmutable('-10 days'));
        $elsewhere = new Event(self::PREFIX . ' Elsewhere', new \DateTimeImmutable('+5 days'));
        foreach ([$soon, $later, $past] as $event) {
            $event->categories->add($this->category);
        }
        $elsewhere->categories->add($this->other);

        $pool = new SharedStock(self::PREFIX . ' totaalcapaciteit');
        $pool->stockQuantity = 42;
        $this->em->persist($pool);
        $type = new TicketType($soon, 'Regulier', Money::EUR(1500));
        $type->sharedStock = $pool;
        $soon->ticketTypes->add($type);

        foreach ([$soon, $later, $past, $elsewhere] as $event) {
            $this->em->persist($event);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testKassaPageRendersThePickerWithNoShowSelected(): void
    {
        $this->client->loginUser($this->user, 'register');
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select#event-category'));
        self::assertStringContainsString('Geen show geselecteerd', $crawler->text());
        self::assertStringContainsString('Selecteer een show', $crawler->text());
    }

    public function testListsUpcomingShowsOfTheChosenCategoryWithTheirStockPools(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterEventPicker', client: $this->client);
        $component->set('category', $this->category->id->toRfc4122());

        $html = (string) $component->render();
        self::assertStringContainsString(self::PREFIX . ' Soon', $html);
        self::assertStringContainsString(self::PREFIX . ' Later', $html);
        self::assertStringContainsString(self::PREFIX . ' totaalcapaciteit', $html);
        self::assertMatchesRegularExpression('/tabular-nums[^>]*>42</', $html);
        self::assertStringNotContainsString(self::PREFIX . ' Past', $html);
        self::assertStringNotContainsString(self::PREFIX . ' Elsewhere', $html);
        self::assertLessThan(strpos($html, self::PREFIX . ' Later'), strpos($html, self::PREFIX . ' Soon'));
    }

    public function testSearchFiltersByShowName(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterEventPicker', client: $this->client);
        $component->set('category', $this->category->id->toRfc4122());
        $component->set('search', 'later');

        $html = (string) $component->render();
        self::assertStringContainsString(self::PREFIX . ' Later', $html);
        self::assertStringNotContainsString(self::PREFIX . ' Soon', $html);
    }

    public function testSelectingAShowFillsTheRightHalfAndChangingCategoryClearsIt(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterEventPicker', client: $this->client);
        $component->set('category', $this->category->id->toRfc4122());
        $soonId = (string) $this->findEvent(self::PREFIX . ' Soon')->id;

        $component->call('select', ['id' => $soonId]);
        $html = (string) $component->render();
        self::assertStringContainsString('<h2 class="text-lg font-semibold">' . self::PREFIX . ' Soon</h2>', $html);
        self::assertStringNotContainsString('Geen show geselecteerd', $html);

        $component->set('category', $this->other->id->toRfc4122());
        $html = (string) $component->render();
        self::assertStringContainsString('Geen show geselecteerd', $html);
        self::assertStringContainsString(self::PREFIX . ' Elsewhere', $html);
    }

    public function testSelectingAnUnknownOrForeignShowSelectsNothing(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterEventPicker', client: $this->client);
        $component->set('category', $this->category->id->toRfc4122());

        $component->call('select', ['id' => 'not-a-uuid']);
        self::assertStringContainsString('Geen show geselecteerd', (string) $component->render());

        $component->call('select', ['id' => (string) $this->findEvent(self::PREFIX . ' Elsewhere')->id]);
        self::assertStringContainsString('Geen show geselecteerd', (string) $component->render());
    }

    public function testActionsAreDeniedWithoutTheRegisterRole(): void
    {
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $component = $this->createLiveComponent(name: 'RegisterEventPicker', client: $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('select', ['id' => (string) $this->findEvent(self::PREFIX . ' Soon')->id]);
    }

    public function testTotalsAndGroupDiscountFollowTheQuantities(): void
    {
        $component = $this->selectSoon();
        $typeId = (string) $this->soonTicketType()->id;

        $component->set('quantities', [$typeId => '4']);
        $html = (string) $component->render();
        self::assertStringContainsString('€ 60,00', $html);
        self::assertStringContainsString('Totaal aantal kaartjes', $html);

        $component->call('addDiscount', ['kind' => 'group']);
        $html = (string) $component->render();
        self::assertStringContainsString('Groepskorting (10%)', $html);
        self::assertStringContainsString('− € 6,00', $html);
        self::assertStringContainsString('€ 54,00', $html);

        $component->call('removeDiscount');
        self::assertStringNotContainsString('Groepskorting (10%)</span>', (string) $component->render());
    }

    public function testSelectingAShowSeedsAZeroQuantityPerTicketType(): void
    {
        $component = $this->selectSoon();

        // The browser may only write `quantities.<id>` when that key exists in the data.
        self::assertSame(
            [(string) $this->soonTicketType()->id => 0],
            $component->component()->quantities,
        );
    }

    public function testPlusAndMinusButtonsChangeTheQuantity(): void
    {
        $component = $this->selectSoon();
        $typeId = (string) $this->soonTicketType()->id;

        $component->call('adjust', ['id' => $typeId, 'delta' => 1]);
        $component->call('adjust', ['id' => $typeId, 'delta' => 1]);
        self::assertStringContainsString('€ 30,00', (string) $component->render());

        $component->call('adjust', ['id' => $typeId, 'delta' => -1]);
        $component->call('adjust', ['id' => $typeId, 'delta' => -1]);
        $component->call('adjust', ['id' => $typeId, 'delta' => -1]);
        self::assertStringNotContainsString('border-l-4 border-[#c62828] pl-3', (string) $component->render());

        $component->call('adjust', ['id' => 'not-a-ticket-type', 'delta' => 1]);
        self::assertStringNotContainsString('border-l-4 border-[#c62828] pl-3', (string) $component->render());
    }

    public function testSaveNeedsCustomerAndTickets(): void
    {
        $component = $this->selectSoon();

        $component->call('save');
        self::assertStringContainsString('Vul voornaam, achternaam en woonplaats in.', (string) $component->render());

        $component->set('firstName', 'Piet');
        $component->set('lastName', 'Puk');
        $component->set('city', 'Utrecht');
        $component->call('save');
        self::assertStringContainsString('Kies minstens één kaartje.', (string) $component->render());
    }

    public function testSaveCreatesACompletedRegisterOrderWithDiscountAndNote(): void
    {
        $component = $this->selectSoon();
        $typeId = (string) $this->soonTicketType()->id;
        $component->set('firstName', 'Piet');
        $component->set('lastName', 'Puk');
        $component->set('city', 'Utrecht');
        $component->set('note', 'Aan de kassa');
        $component->set('quantities', [$typeId => '2']);
        $component->call('addDiscount', ['kind' => 'fixed']);
        $component->set('discountValue', '5,00');

        $component->call('save');
        $html = (string) $component->render();
        self::assertStringContainsString('is opgeslagen', $html);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['note' => 'Aan de kassa']);
        self::assertNotNull($order);
        self::assertSame('register', $order->source->value);
        self::assertSame('2500', $order->totalGross->getAmount());
        self::assertSame('Puk', $order->billing->lastName);
        self::assertSame('Utrecht', $order->billing->city);
        self::assertCount(2, $order->tickets);
        self::assertStringContainsString($order->orderNumber, $html);
    }

    public function testInvalidPercentageIsRefused(): void
    {
        $component = $this->selectSoon();
        $component->set('firstName', 'Piet');
        $component->set('lastName', 'Puk');
        $component->set('city', 'Utrecht');
        $component->set('quantities', [(string) $this->soonTicketType()->id => '1']);
        $component->call('addDiscount', ['kind' => 'percent']);
        $component->set('discountValue', '150');

        $component->call('save');
        self::assertStringContainsString('geldig percentage', (string) $component->render());
    }

    private function selectSoon(): \Symfony\UX\LiveComponent\Test\TestLiveComponent
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterEventPicker', client: $this->client);
        $component->set('category', $this->category->id->toRfc4122());
        $component->call('select', ['id' => (string) $this->findEvent(self::PREFIX . ' Soon')->id]);

        return $component;
    }

    private function soonTicketType(): TicketType
    {
        return $this->findEvent(self::PREFIX . ' Soon')->ticketTypes->first();
    }

    private function findEvent(string $name): Event
    {
        $this->em->clear();

        return $this->em->getRepository(Event::class)->findOneBy(['name' => $name]);
    }

    private function cleanUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        $sub = "SELECT id FROM customer_order WHERE note = 'Aan de kassa'";
        $this->em->getConnection()->executeStatement("DELETE FROM ticket WHERE order_id IN ($sub)");
        $this->em->getConnection()->executeStatement("DELETE FROM payment WHERE order_id IN ($sub)");
        $this->em->getConnection()->executeStatement("DELETE FROM stock_reservation WHERE order_id IN ($sub)");
        foreach ($this->em->getRepository(Order::class)->findBy(['note' => 'Aan de kassa']) as $order) {
            $customer = $order->customer;
            $this->em->remove($order);
            $this->em->flush();
            if ($customer !== null && $customer->email === null) {
                $this->em->remove($customer);
                $this->em->flush();
            }
        }
        $like = self::PREFIX . '%';
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM event_event_category WHERE event_id IN (SELECT id FROM event WHERE name LIKE ?)', [$like]);
        $conn->executeStatement('DELETE FROM ticket_type WHERE event_id IN (SELECT id FROM event WHERE name LIKE ?)', [$like]);
        $conn->executeStatement('UPDATE event SET display_stock_id = NULL WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM event WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM shared_stock WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM event_category WHERE name LIKE ?', [$like]);
        $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::USER_EMAIL]);
    }
}
