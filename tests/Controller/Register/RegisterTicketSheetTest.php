<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\Ticket;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\TicketStatus;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RegisterTicketSheetTest extends WebTestCase
{
    private const USER_EMAIL = 'register-sheet-user@example.com';
    private const CUSTOMER_EMAIL = 'register-sheet-customer@example.com';
    private const EVENT_NAME = 'Register Sheet Show';
    private const DONE = 'RSHEET-DONE';
    private const PENDING = 'RSHEET-PEND';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) ($_SERVER['REGISTER_HOST'] ?? 'register.localhost');
        $this->client = static::createClient(server: ['HTTP_HOST' => $host]);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $this->user = new User(self::USER_EMAIL, 'unused', ['ROLE_REGISTER']);
        $this->user->name = 'Kassa Sheet';
        $customer = new Customer(self::CUSTOMER_EMAIL, 'Piet', 'Puk');
        $event = new Event(self::EVENT_NAME, new \DateTimeImmutable('+2 days'));
        foreach ([$this->user, $customer, $event] as $entity) {
            $this->em->persist($entity);
        }

        $this->order(self::DONE, OrderStatus::COMPLETED, $customer, $event, [
            ['RSHEET-T1', TicketStatus::ISSUED],
            ['RSHEET-T2', TicketStatus::CHECKED_IN],
            ['RSHEET-VOID', TicketStatus::VOID],
        ]);
        $this->order(self::PENDING, OrderStatus::PENDING, $customer, $event, [['RSHEET-T3', TicketStatus::ISSUED]]);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testSheetListsValidTicketsWithQrCodesAndOpensThePrintDialog(): void
    {
        $this->client->loginUser($this->user, 'register');
        $crawler = $this->client->request('GET', '/bestellingen/' . self::DONE . '/tickets');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.meta', self::DONE);
        self::assertSelectorTextContains('.meta', 'Piet Puk');
        self::assertSelectorTextContains('header.title', self::EVENT_NAME);
        // The void ticket is not printed; the others get one QR each.
        self::assertCount(2, $crawler->filter('.ticket img[src^="data:image/png;base64,"]'));
        self::assertSelectorTextContains('.tickets', 'RSHEET-T1');
        self::assertSelectorTextContains('.tickets', 'RSHEET-T2');
        self::assertStringNotContainsString('RSHEET-VOID', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('window.print()', (string) $this->client->getResponse()->getContent());
    }

    public function testPageBreakRulesKeepEveryTicketInOnePiece(): void
    {
        $this->client->loginUser($this->user, 'register');
        $this->client->request('GET', '/bestellingen/' . self::DONE . '/tickets');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('@page { size: A4 portrait; margin: 12mm; }', $html);
        self::assertMatchesRegularExpression('/\.ticket \{[^}]*display: inline-block[^}]*break-inside: avoid/s', $html);
    }

    public function testUncompletedOrderPrintsNoTicketsAndDoesNotOpenThePrintDialog(): void
    {
        $this->client->loginUser($this->user, 'register');
        $crawler = $this->client->request('GET', '/bestellingen/' . self::PENDING . '/tickets');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ticket'));
        self::assertSelectorTextContains('.empty', 'geen geldige tickets');
        self::assertStringNotContainsString('window.print();', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownOrderIsNotFound(): void
    {
        $this->client->loginUser($this->user, 'register');
        $this->client->request('GET', '/bestellingen/RSHEET-NOPE/tickets');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/bestellingen/' . self::DONE . '/tickets');

        self::assertResponseRedirects();
    }

    /**
     * @param list<array{0: string, 1: TicketStatus}> $tickets
     */
    private function order(string $number, OrderStatus $status, Customer $customer, Event $event, array $tickets): void
    {
        $type = new TicketType($event, 'Vanaf 13 jaar', Money::EUR(1200));
        $this->em->persist($type);
        $order = new Order($number, $customer, 900);
        $order->status = $status;
        $order->totalGross = Money::EUR(1200 * count($tickets));
        $order->tax = Money::EUR(99);
        $line = new OrderLineItem(
            $order,
            $type,
            'Vanaf 13 jaar',
            count($tickets),
            Money::EUR(1101),
            Money::EUR(1200),
            Money::EUR(1101),
            Money::EUR(99),
            Money::EUR(1200 * count($tickets)),
        );
        $order->lineItems->add($line);
        $this->em->persist($order);

        foreach ($tickets as $i => [$code, $ticketStatus]) {
            $ticket = new Ticket(
                $code,
                $order,
                $line,
                $type,
                $event,
                'Vanaf 13 jaar',
                $event->name,
                $event->startsAt,
                new \DateTimeImmutable(),
                $i,
            );
            $ticket->status = $ticketStatus;
            $this->em->persist($ticket);
            $order->tickets->add($ticket);
        }
    }

    private function cleanUp(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM App\Entity\Ticket t WHERE t.ticketCode LIKE :p')->setParameter('p', 'RSHEET-%')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Order o WHERE o.orderNumber LIKE :p')->setParameter('p', 'RSHEET-%')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Event e WHERE e.name = :n')->setParameter('n', self::EVENT_NAME)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Customer c WHERE c.email = :e')->setParameter('e', self::CUSTOMER_EMAIL)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :e')->setParameter('e', self::USER_EMAIL)->execute();
    }
}
