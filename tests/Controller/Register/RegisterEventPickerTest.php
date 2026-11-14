<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\Event;
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
        self::assertStringContainsString(self::PREFIX . ' totaalcapaciteit · 42', $html);
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

    private function findEvent(string $name): Event
    {
        $this->em->clear();

        return $this->em->getRepository(Event::class)->findOneBy(['name' => $name]);
    }

    private function cleanUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
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
