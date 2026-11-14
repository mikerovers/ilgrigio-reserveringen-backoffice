<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\EventCategory;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\SharedStock;
use App\Entity\StockReservation;
use App\Entity\Ticket;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RegisterRebookTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const ORDER = 'RB-ORDER-1';
    private const USER_EMAIL = 'register-rebook-user@example.com';
    private const CUSTOMER_EMAIL = 'register-rebook-customer@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Event $from;
    private Event $to;
    private Event $otherCategory;
    private TicketType $fromAdult;
    private TicketType $fromChild;
    private TicketType $toAdult;
    private TicketType $toChild;
    private SharedStock $fromPool;
    private SharedStock $toPool;
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
        $customer = new Customer(self::CUSTOMER_EMAIL, 'Piet', 'Rebook');
        $this->em->persist($customer);

        $this->category = new EventCategory('RB Categorie');
        $this->other = new EventCategory('RB Andere');
        $this->em->persist($this->category);
        $this->em->persist($this->other);

        $this->fromPool = new SharedStock('RB from');
        $this->fromPool->stockQuantity = 8;
        $this->toPool = new SharedStock('RB to');
        $this->toPool->stockQuantity = 1;
        $this->em->persist($this->fromPool);
        $this->em->persist($this->toPool);

        $future = new \DateTimeImmutable('+10 days');
        $this->from = $this->event('RB Van', $future, $this->category);
        $this->to = $this->event('RB Naar', $future->modify('+7 days'), $this->category);
        $this->otherCategory = $this->event('RB Elders', $future->modify('+8 days'), $this->other);

        $this->fromAdult = $this->type($this->from, 'Volwassene', $this->fromPool);
        $this->fromChild = $this->type($this->from, 'Kind', $this->fromPool);
        $this->toAdult = $this->type($this->to, 'volwassene ', $this->toPool);
        $this->toChild = $this->type($this->to, 'Kind', $this->toPool);
        $this->type($this->otherCategory, 'Volwassene', null);

        $order = new Order(self::ORDER, $customer, 900);
        $order->status = OrderStatus::COMPLETED;
        $this->em->persist($order);
        $index = 0;
        foreach ([[$this->fromAdult, 2], [$this->fromChild, 1]] as [$type, $qty]) {
            $money = Money::EUR(1000 * $qty);
            $line = new OrderLineItem($order, $type, $type->name, $qty, Money::EUR(917), Money::EUR(1000), $money, Money::EUR(0), $money);
            $order->lineItems->add($line);
            $this->em->persist($line);
            for ($i = 0; $i < $qty; ++$i) {
                $ticket = new Ticket('RBCODE' . $index++, $order, $line, $type, $this->from, $type->name, $this->from->name, $this->from->startsAt, new \DateTimeImmutable(), $i);
                $order->tickets->add($ticket);
                $this->em->persist($ticket);
            }
        }
        $this->em->persist(new StockReservation($order, $this->fromPool, 3, new \DateTimeImmutable()));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testModalListsOnlyShowsOfTheSameCategoryAndAutoMapsByName(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);

        $component->call('openRebook', ['orderNumber' => self::ORDER]);
        $html = (string) $component->render();
        self::assertStringContainsString('RB Naar', $html);
        self::assertStringNotContainsString('RB Elders', $html);

        $component->set('rebookEvent', $this->to->id->toRfc4122());
        self::assertSame(
            [$this->fromAdult->id->toRfc4122() => $this->toAdult->id->toRfc4122(), $this->fromChild->id->toRfc4122() => $this->toChild->id->toRfc4122()],
            $component->component()->rebookMap,
        );
        self::assertStringContainsString('overboekt', (string) $component->render());
    }

    public function testRebookMovesTicketsAndOverbooksTheNewShow(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);
        $component->call('openRebook', ['orderNumber' => self::ORDER]);
        $component->set('rebookEvent', $this->to->id->toRfc4122());
        $component->call('confirmRebook');

        self::assertStringContainsString('is omgeboekt naar RB Naar', (string) $component->render());

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => self::ORDER]);
        self::assertCount(3, $order->tickets);
        foreach ($order->tickets as $ticket) {
            self::assertSame('RB Naar', $ticket->event->name);
            self::assertSame('RB Naar', $ticket->eventNameSnapshot);
            self::assertSame(TicketStatus::ISSUED, $ticket->status);
        }
        foreach ($order->lineItems as $line) {
            self::assertSame('RB Naar', $line->ticketType->event->name);
        }
        $from = $this->em->getRepository(SharedStock::class)->find($this->fromPool->id);
        $to = $this->em->getRepository(SharedStock::class)->find($this->toPool->id);
        self::assertSame(11, $from->stockQuantity);
        self::assertSame(-2, $to->stockQuantity);
        self::assertStringContainsString('Omgeboekt door Kassa Test', $order->notes->last()->content);
    }

    public function testRebookRefusedWithoutCompleteMappingOrAfterCheckIn(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);
        $component->call('openRebook', ['orderNumber' => self::ORDER]);
        $component->set('rebookEvent', $this->to->id->toRfc4122());
        $component->set('rebookMap', [$this->fromAdult->id->toRfc4122() => $this->toAdult->id->toRfc4122()]);
        $component->call('confirmRebook');
        self::assertStringContainsString('Kies een tickettype voor', (string) $component->render());

        $this->em->getConnection()->executeStatement(
            "UPDATE ticket SET status = :s WHERE ticket_code = 'RBCODE0'",
            ['s' => TicketStatus::CHECKED_IN->value],
        );
        $this->em->clear();
        $component->set('rebookMap', $component->component()->rebookMap + [$this->fromChild->id->toRfc4122() => $this->toChild->id->toRfc4122()]);
        $component->call('confirmRebook');
        self::assertStringContainsString('al ingecheckt', (string) $component->render());
    }

    private function event(string $name, \DateTimeImmutable $start, EventCategory $category): Event
    {
        $event = new Event($name, $start);
        $event->categories->add($category);
        $this->em->persist($event);

        return $event;
    }

    private function type(Event $event, string $name, ?SharedStock $pool): TicketType
    {
        $type = new TicketType($event, $name, Money::EUR(1000));
        $type->sharedStock = $pool;
        $event->ticketTypes->add($type);
        $this->em->persist($type);

        return $type;
    }

    private function cleanUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        $this->em->getConnection()->executeStatement("DELETE FROM ticket WHERE ticket_code LIKE 'RBCODE%'");
        $order = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => self::ORDER]);
        if ($order !== null) {
            $this->em->remove($order);
            $this->em->flush();
        }
        foreach ($this->em->getRepository(Event::class)->findBy([]) as $event) {
            if (str_starts_with($event->name, 'RB ')) {
                $this->em->remove($event);
            }
        }
        $this->em->flush();
        foreach ([SharedStock::class, EventCategory::class] as $class) {
            foreach ($this->em->getRepository($class)->findBy([]) as $entity) {
                if (str_starts_with($entity->name, 'RB ')) {
                    $this->em->remove($entity);
                }
            }
        }
        foreach ([[User::class, self::USER_EMAIL], [Customer::class, self::CUSTOMER_EMAIL]] as [$class, $email]) {
            $entity = $this->em->getRepository($class)->findOneBy(['email' => $email]);
            if ($entity !== null) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
    }
}
