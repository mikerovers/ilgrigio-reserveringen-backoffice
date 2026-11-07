<?php

declare(strict_types=1);

namespace App\Tests\Controller\Scanner;

use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\Ticket;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use App\Repository\EventRepository;
use App\Repository\TicketRepository;
use App\Service\VenueTime;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ScannerTest extends WebTestCase
{
    private const HOST = 'scanner.localhost';
    private const CHECK_IN_EMAIL = 'scanner-test-checkin@example.com';
    private const REGISTER_EMAIL = 'scanner-test-register@example.com';
    private const EVENT_NAME = 'Scanner Test Event';
    private const OTHER_EVENT_NAME = 'Scanner Test Other Event';
    private const FAR_EVENT_NAME = 'Scanner Test Far Event';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Event $event;
    private Event $otherEvent;
    private Event $farEvent;
    private User $checkInUser;
    private User $registerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient(server: ['HTTP_HOST' => self::HOST]);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $now = new \DateTimeImmutable();

        $this->event = new Event(self::EVENT_NAME, $now->modify('+2 hours'));
        $this->event->doorsOpenAt = $now->modify('+1 hour');
        $this->event->location = 'Bossebaan 5B, Haaren NB';

        $this->otherEvent = new Event(self::OTHER_EVENT_NAME, $now->modify('+3 hours'));

        // Doors open in 2 days: not selectable yet (doors open - 1 day).
        $this->farEvent = new Event(self::FAR_EVENT_NAME, $now->modify('+2 days 3 hours'));
        $this->farEvent->doorsOpenAt = $now->modify('+2 days 2 hours');

        $this->checkInUser = new User(self::CHECK_IN_EMAIL, 'unused', ['ROLE_CHECK_IN']);
        $this->checkInUser->name = 'Balie 1';
        $this->registerUser = new User(self::REGISTER_EMAIL, 'unused', ['ROLE_REGISTER']);
        $this->registerUser->name = 'Kassa';

        $customer = new Customer('scanner-test-customer@example.com', 'Scan', 'Test');
        foreach ([$this->event, $this->otherEvent, $this->farEvent, $this->checkInUser, $this->registerUser, $customer] as $entity) {
            $this->em->persist($entity);
        }

        $this->issueTicket($customer, $this->event, 'SCANTEST-1', 'SCANTEST-ORDER-1', TicketStatus::ISSUED);
        $this->issueTicket($customer, $this->event, 'SCANTEST-2', 'SCANTEST-ORDER-2', TicketStatus::ISSUED);
        $this->issueTicket($customer, $this->event, 'SCANTEST-VOID', 'SCANTEST-ORDER-3', TicketStatus::VOID);
        $this->issueTicket($customer, $this->otherEvent, 'SCANTEST-OTHER', 'SCANTEST-ORDER-4', TicketStatus::ISSUED);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://' . self::HOST . '/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
    }

    public function testUserWithoutCheckInRoleIsForbidden(): void
    {
        $this->client->loginUser($this->registerUser, 'scanner');
        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCheckInUserSeesOnlyScannableEvents(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->client->request('GET', '/', ['event' => (string) $this->event->id]);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::EVENT_NAME, $html);
        self::assertStringContainsString(self::OTHER_EVENT_NAME, $html);
        self::assertStringNotContainsString(self::FAR_EVENT_NAME, $html);
        self::assertStringContainsString('Bossebaan 5B, Haaren NB', $html);
        self::assertStringContainsString('Balie 1', $html);
    }

    public function testScannableEventsFollowDoorsOpenMinusOneDay(): void
    {
        /** @var EventRepository $repository */
        $repository = static::getContainer()->get(EventRepository::class);
        $names = array_map(static fn (Event $e): string => $e->name, $repository->findScannable(new \DateTimeImmutable()));

        self::assertContains(self::EVENT_NAME, $names);
        self::assertNotContains(self::FAR_EVENT_NAME, $names);

        // One day later the far event's doors are within a day.
        $later = array_map(
            static fn (Event $e): string => $e->name,
            $repository->findScannable((new \DateTimeImmutable())->modify('+1 day 2 hours')),
        );
        self::assertContains(self::FAR_EVENT_NAME, $later);
    }

    public function testStartingTodayListsEveryShowOfTheVenueDay(): void
    {
        /** @var EventRepository $repository */
        $repository = static::getContainer()->get(EventRepository::class);

        // Noon (venue time) on a fixed day: a show at 03:00 and one at 23:30 that day count,
        // one at 00:30 the next day does not, whatever their doors times.
        $noon = VenueTime::fromLocal('2031-05-15 12:00:00');
        $early = new Event('Scanner Test Early', VenueTime::fromLocal('2031-05-15 03:00:00'));
        $late = new Event('Scanner Test Late', VenueTime::fromLocal('2031-05-15 23:30:00'));
        $tomorrow = new Event('Scanner Test Tomorrow', VenueTime::fromLocal('2031-05-16 00:30:00'));
        foreach ([$early, $late, $tomorrow] as $event) {
            $this->em->persist($event);
        }
        $this->em->flush();

        try {
            $names = array_map(static fn (Event $e): string => $e->name, $repository->findStartingToday($noon));

            self::assertSame(['Scanner Test Early', 'Scanner Test Late'], $names);
        } finally {
            foreach ([$early, $late, $tomorrow] as $event) {
                $this->em->remove($event);
            }
            $this->em->flush();
        }
    }

    public function testStatsCountIssuedAndCheckedInButNotVoid(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->client->request('GET', '/events/' . $this->event->id . '/stats');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['expected' => 2, 'checkedIn' => 0, 'remaining' => 2],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testScanAdmitsTicketOnceAndReportsRepeat(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');

        $first = $this->scan('SCANTEST-1');
        self::assertSame('ADMITTED', $first['result']);
        self::assertSame('Regular', $first['ticketTypeName']);
        self::assertSame('SCANTEST-ORDER-1', $first['orderNumber']);
        self::assertSame(['expected' => 2, 'checkedIn' => 1, 'remaining' => 1], $first['stats']);

        $second = $this->scan('SCANTEST-1');
        self::assertSame('ALREADY_CHECKED_IN', $second['result']);
        self::assertSame('Balie 1', $second['checkedInBy']);
        self::assertNotNull($second['checkedInTime']);
        self::assertSame(1, $second['stats']['checkedIn']);
    }

    public function testScanRecordsTheScannerUserOnTheTicket(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->scan('SCANTEST-2');

        /** @var TicketRepository $tickets */
        $tickets = static::getContainer()->get(TicketRepository::class);
        $this->em->clear();
        $ticket = $tickets->findOneBy(['ticketCode' => 'SCANTEST-2']);

        self::assertSame(TicketStatus::CHECKED_IN, $ticket->status);
        self::assertSame(self::CHECK_IN_EMAIL, $ticket->checkedInBy);
    }

    public function testVoidUnknownAndOtherEventTicketsAreRefused(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');

        self::assertSame('VOID', $this->scan('SCANTEST-VOID')['result']);
        self::assertSame('NOT_FOUND', $this->scan('DOES-NOT-EXIST')['result']);
        // A valid ticket, but for another show: refused here, and still unused there.
        self::assertSame('NOT_FOUND', $this->scan('SCANTEST-OTHER')['result']);

        /** @var TicketRepository $tickets */
        $tickets = static::getContainer()->get(TicketRepository::class);
        self::assertSame(1, $tickets->countByStatusForEvent($this->otherEvent)['issued']);
    }

    public function testScanRequiresTheCheckInRole(): void
    {
        $this->client->loginUser($this->registerUser, 'scanner');
        $this->client->request(
            'POST',
            '/events/' . $this->event->id . '/scan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['ticketCode' => 'SCANTEST-1']),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBlankCodeIsRejected(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->client->request(
            'POST',
            '/events/' . $this->event->id . '/scan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['ticketCode' => '   ']),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAllScansListsOnlyTheScansOfThisBalie(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->scan('SCANTEST-1');

        $this->client->request('GET', '/scans', ['event' => (string) $this->event->id]);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('SCANTEST-ORDER-1', $html);
        self::assertStringNotContainsString('SCANTEST-ORDER-2', $html);
        self::assertStringContainsString('1 scan door Balie 1', $html);
        self::assertStringContainsString('data-order-number="SCANTEST-ORDER-1"', $html);
        self::assertStringContainsString('<dialog', $html);
    }

    public function testMercureTokenIsIssuedForTheEventTopic(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->client->request('GET', '/events/' . $this->event->id . '/mercure-token');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('scanner/events/' . $this->event->id->toRfc4122(), $data['topic']);
        self::assertNotSame('', $data['token']);
        self::assertStringContainsString('/.well-known/mercure', $data['hub']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testOrderEndpointListsTheTicketsOfTheOrder(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->scan('SCANTEST-1');

        $this->client->request('GET', '/events/' . $this->event->id . '/orders/SCANTEST-ORDER-1');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('SCANTEST-ORDER-1', $data['orderNumber']);
        self::assertSame('Scan Test', $data['customerName']);
        self::assertSame(1, $data['total']);
        self::assertSame(1, $data['scanned']);
        self::assertSame('SCANTEST-1', $data['tickets'][0]['code']);
        self::assertFalse($data['tickets'][0]['scannable']);
        self::assertNotNull($data['tickets'][0]['checkedInTime']);
        self::assertArrayNotHasKey('price', $data['tickets'][0]);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testOrderEndpointMarksUnscannedTicketsScannable(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->client->request('GET', '/events/' . $this->event->id . '/orders/SCANTEST-ORDER-2');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['tickets'][0]['scannable']);
        self::assertSame(0, $data['scanned']);
    }

    public function testOrderEndpointAlsoShowsOrdersWithVoidTickets(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');
        $this->client->request('GET', '/events/' . $this->event->id . '/orders/SCANTEST-ORDER-3');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('void', $data['tickets'][0]['status']);
        self::assertFalse($data['tickets'][0]['scannable']);
    }

    public function testOrderEndpointRefusesUnknownOrdersAndOtherShows(): void
    {
        $this->client->loginUser($this->checkInUser, 'scanner');

        $this->client->request('GET', '/events/' . $this->event->id . '/orders/NOPE');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // An order that only has tickets for another show is not shown here
        $this->client->request('GET', '/events/' . $this->event->id . '/orders/SCANTEST-ORDER-4');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOrderEndpointNeedsCheckInRole(): void
    {
        $this->client->loginUser($this->registerUser, 'scanner');
        $this->client->request('GET', '/events/' . $this->event->id . '/orders/SCANTEST-ORDER-1');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** @return array<string, mixed> */
    private function scan(string $code): array
    {
        $this->client->request(
            'POST',
            '/events/' . $this->event->id . '/scan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['ticketCode' => $code, 'scanId' => 'test-scan']),
        );
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function issueTicket(Customer $customer, Event $event, string $code, string $orderNumber, TicketStatus $status): void
    {
        $ticketType = new TicketType($event, 'Regular', Money::EUR(1000));
        $this->em->persist($ticketType);

        $order = new Order($orderNumber, $customer, 900);
        $order->status = OrderStatus::COMPLETED;
        $line = new OrderLineItem(
            $order,
            $ticketType,
            'Regular',
            1,
            Money::EUR(0),
            Money::EUR(0),
            Money::EUR(0),
            Money::EUR(0),
            Money::EUR(0),
        );
        $order->lineItems->add($line);
        $this->em->persist($order);

        $ticket = new Ticket($code, $order, $line, $ticketType, $event, 'Regular', $event->name, $event->startsAt, new \DateTimeImmutable());
        $ticket->status = $status;
        $this->em->persist($ticket);
    }

    private function cleanUp(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM App\Entity\Ticket t WHERE t.ticketCode LIKE :p')->setParameter('p', 'SCANTEST-%')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Order o WHERE o.orderNumber LIKE :p')->setParameter('p', 'SCANTEST-%')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Event e WHERE e.name IN (:n)')
            ->setParameter('n', [self::EVENT_NAME, self::OTHER_EVENT_NAME, self::FAR_EVENT_NAME])->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Customer c WHERE c.email = :e')->setParameter('e', 'scanner-test-customer@example.com')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.email IN (:e)')
            ->setParameter('e', [self::CHECK_IN_EMAIL, self::REGISTER_EMAIL])->execute();
    }
}
