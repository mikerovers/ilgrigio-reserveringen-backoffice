<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Event;
use App\Entity\Order;
use App\Entity\OrderLineItem;
use App\Entity\Ticket;
use App\Entity\TicketType;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SaleReportApiTest extends WebTestCase
{
    private const EMAIL = 'sale-report-test@example.com';
    private const PASSWORD = 'correct-horse-battery-staple';
    private const YEAR = 2001;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $user = new User(self::EMAIL, '', ['ROLE_REGISTER']);
        $user->password = static::getContainer()->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, self::PASSWORD);
        $this->em->persist($user);

        $event = new Event('Sale Report Event', new \DateTimeImmutable('2001-06-01'));
        $ticketType = new TicketType($event, 'Regular', Money::EUR(1000));
        $customer = new Customer('sale-report-customer@example.com', 'Sale', 'Report');
        $this->em->persist($event);
        $this->em->persist($ticketType);
        $this->em->persist($customer);

        $this->createOrder('SALEREP-DONE', OrderStatus::COMPLETED, $customer, $event, $ticketType, 2);
        $this->createOrder('SALEREP-PENDING', OrderStatus::PENDING, $customer, $event, $ticketType, 1);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function createOrder(
        string $number,
        OrderStatus $status,
        Customer $customer,
        Event $event,
        TicketType $ticketType,
        int $tickets,
    ): void {
        $order = new Order($number, $customer, 900);
        $order->status = $status;
        $order->createdAt = new \DateTimeImmutable(self::YEAR . '-03-01 12:00:00');
        $line = new OrderLineItem(
            $order,
            $ticketType,
            'Regular',
            $tickets,
            Money::EUR(0),
            Money::EUR(0),
            Money::EUR(0),
            Money::EUR(0),
            Money::EUR(0),
        );
        $order->lineItems->add($line);
        $this->em->persist($order);

        for ($i = 0; $i < $tickets; $i++) {
            $this->em->persist(new Ticket(
                $number . '-' . $i,
                $order,
                $line,
                $ticketType,
                $event,
                'Regular',
                $event->name,
                $event->startsAt,
                new \DateTimeImmutable(),
                $i,
            ));
        }
    }

    private function cleanUp(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM App\Entity\Ticket t WHERE t.ticketCode LIKE :p')
            ->setParameter('p', 'SALEREP-%')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Order o WHERE o.orderNumber LIKE :p')
            ->setParameter('p', 'SALEREP-%')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Event e WHERE e.name = :n')
            ->setParameter('n', 'Sale Report Event')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Customer c WHERE c.email = :e')
            ->setParameter('e', 'sale-report-customer@example.com')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :e')
            ->setParameter('e', self::EMAIL)->execute();
    }

    /** @return array<int, array{year: int, ticketsSold: int}> */
    private function report(): array
    {
        $this->client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('GET', '/api/sale-report', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testRequiresJwt(): void
    {
        $this->client->request('GET', '/api/sale-report');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCountsOnlyTicketsOfCompletedOrdersPerYear(): void
    {
        $years = array_column($this->report(), 'ticketsSold', 'year');

        self::assertSame(2, $years[self::YEAR]);
    }
}
