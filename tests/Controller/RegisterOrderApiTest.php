<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\SharedStock;
use App\Entity\TicketType;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterOrderApiTest extends WebTestCase
{
    private const REGISTER_EMAIL = 'register-order-test@example.com';
    private const PLAIN_EMAIL = 'register-order-plain@example.com';
    private const CUSTOMER_EMAIL = 'register-order-customer@example.com';
    private const PASSWORD = 'correct-horse-battery-staple';
    private const EVENT_NAME = 'Register order test event';
    private const POOL_NAME = 'Register order test pool';
    private const INITIAL_STOCK = 5;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private string $ticketTypeId;
    private string $poolId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([self::REGISTER_EMAIL => ['ROLE_REGISTER'], self::PLAIN_EMAIL => []] as $email => $roles) {
            $user = new User($email, '', $roles);
            $user->password = $hasher->hashPassword($user, self::PASSWORD);
            $this->em->persist($user);
        }

        $pool = new SharedStock(self::POOL_NAME);
        $pool->stockQuantity = self::INITIAL_STOCK;
        $event = new Event(self::EVENT_NAME, new \DateTimeImmutable('+30 days'));
        $ticketType = new TicketType($event, 'Regulier', Money::EUR(1000));
        $ticketType->sharedStock = $pool;
        $event->ticketTypes->add($ticketType);
        $this->em->persist($pool);
        $this->em->persist($event);
        $this->em->persist($ticketType);
        $this->em->flush();

        $this->ticketTypeId = (string) $ticketType->id;
        $this->poolId = (string) $pool->id;
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->em->clear();
        $orders = '(SELECT o.id FROM customer_order o JOIN customer c ON c.id = o.customer_id WHERE c.email = :email OR c.last_name = \'NoEmail\')';
        $params = ['email' => self::CUSTOMER_EMAIL];

        foreach (['ticket', 'stock_reservation', 'payment', 'order_note', 'order_line_item'] as $table) {
            $this->connection->executeStatement("DELETE FROM {$table} WHERE order_id IN {$orders}", $params);
        }
        $this->connection->executeStatement("DELETE FROM customer_order WHERE id IN {$orders}", $params);
        $this->connection->executeStatement('DELETE FROM customer WHERE email = :email OR last_name = \'NoEmail\'', $params);
        $this->connection->executeStatement('DELETE FROM event WHERE name = :name', ['name' => self::EVENT_NAME]);
        $this->connection->executeStatement('DELETE FROM shared_stock WHERE name = :name', ['name' => self::POOL_NAME]);
        $this->connection->executeStatement('DELETE FROM "user" WHERE email IN (:a, :b)', ['a' => self::REGISTER_EMAIL, 'b' => self::PLAIN_EMAIL]);
    }

    private function jwt(string $email): string
    {
        $this->client->request('POST', '/api/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'items' => [['ticketTypeId' => $this->ticketTypeId, 'quantity' => 2]],
            'paymentMethod' => 'cash',
            'customer' => ['email' => self::CUSTOMER_EMAIL, 'firstName' => 'Reg', 'lastName' => 'Order'],
        ], $override);
    }

    /** @return array<string, mixed> */
    private function post(array $body, ?string $email = self::REGISTER_EMAIL, ?string $idempotencyKey = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($email !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt($email);
        }
        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        $this->client->request('POST', '/api/orders', server: $server, content: (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt(self::REGISTER_EMAIL)]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<string, mixed> */
    private function delete(string $orderNumber, ?string $email = self::REGISTER_EMAIL): array
    {
        $server = [];
        if ($email !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt($email);
        }
        $this->client->request('DELETE', '/api/orders/' . $orderNumber, server: $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function stock(): int
    {
        return (int) $this->connection->fetchOne('SELECT stock_quantity FROM shared_stock WHERE id = :id', ['id' => $this->poolId]);
    }

    public function testRequiresJwt(): void
    {
        $this->post($this->payload(), null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRequiresRegisterRole(): void
    {
        $this->post($this->payload(), self::PLAIN_EMAIL);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCashOrderIsCompletedAndFulfilled(): void
    {
        $data = $this->post($this->payload());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('completed', $data['status']);
        self::assertSame('register', $data['source']);
        self::assertSame(2000, $data['totalCents']);
        self::assertSame('cash', $data['paymentMethod']);
        self::assertNull($data['discount']);
        self::assertCount(1, $data['lineItems']);
        self::assertSame(self::INITIAL_STOCK - 2, $this->stock());

        $row = $this->connection->fetchAssociative(
            'SELECT p.status, p.method, p.mollie_payment_id FROM payment p JOIN customer_order o ON o.id = p.order_id WHERE o.order_number = :n',
            ['n' => $data['orderNumber']],
        );
        self::assertSame('paid', $row['status']);
        self::assertSame('cash', $row['method']);
        self::assertNull($row['mollie_payment_id']);

        $tickets = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ticket t JOIN customer_order o ON o.id = t.order_id WHERE o.order_number = :n',
            ['n' => $data['orderNumber']],
        );
        self::assertSame(2, $tickets);
    }

    public function testOrderDetailReturnsTheCreatedOrder(): void
    {
        $created = $this->post($this->payload([
            'discount' => ['type' => 'fixed', 'amount' => 500, 'reason' => 'Goodwill'],
        ]));

        $this->client->request('GET', '/api/orders/' . $created['orderNumber'], server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt(self::REGISTER_EMAIL),
        ]);
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertSame($created['id'], $detail['id']);
        self::assertSame(1500, $detail['totalCents']);
        self::assertSame('Goodwill', $detail['discount']['reason']);
        self::assertSame('cash', $detail['paymentMethod']);
        self::assertCount(1, $detail['lineItems']);
    }

    public function testOrderTicketsListsAllIssuedTickets(): void
    {
        $created = $this->post($this->payload());

        $response = $this->get('/api/orders/' . $created['orderNumber'] . '/tickets');
        $tickets = $response['tickets'];

        self::assertResponseIsSuccessful();
        self::assertSame($created['orderNumber'], $response['orderNumber']);
        self::assertCount(2, $tickets);
        self::assertSame([0, 1], array_column($tickets, 'lineIndex'));
        foreach ($tickets as $ticket) {
            self::assertSame('issued', $ticket['status']);
            self::assertSame('Regulier', $ticket['ticketName']);
            self::assertSame($this->ticketTypeId, $ticket['ticketTypeId']);
            self::assertSame(self::EVENT_NAME, $ticket['eventName']);
            self::assertNotSame('', $ticket['ticketCode']);
            self::assertNull($ticket['checkedInAt']);
            self::assertNull($ticket['checkedInBy']);
        }

        $this->get('/api/orders/UNKNOWN-1/tickets');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOrderTicketsAuth(): void
    {
        $this->client->request('GET', '/api/orders/NOPE-1/tickets');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/orders/NOPE-1/tickets', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt(self::PLAIN_EMAIL),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOrderDetailAuthAndNotFound(): void
    {
        $this->client->request('GET', '/api/orders/NOPE-1');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->client->request('GET', '/api/orders/NOPE-1', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt(self::PLAIN_EMAIL),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', '/api/orders/NOPE-1', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt(self::REGISTER_EMAIL),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOrderListIncludesSource(): void
    {
        $created = $this->post($this->payload());

        $list = $this->get('/api/orders?limit=100');

        self::assertResponseIsSuccessful();
        $item = current(array_filter($list['items'], static fn (array $i): bool => $i['orderNumber'] === $created['orderNumber']));
        self::assertSame('register', $item['source']);
    }

    public function testOrderListSearchMatchesOrderNumberAndCustomerName(): void
    {
        $created = $this->post($this->payload());
        $number = $created['orderNumber'];

        $numbers = fn (string $search): array => array_column(
            $this->get('/api/orders?limit=100&search=' . rawurlencode($search))['items'],
            'orderNumber',
        );

        self::assertContains($number, $numbers(substr($number, 1, -1)));
        self::assertContains($number, $numbers(strtolower($number)));
        self::assertContains($number, $numbers('reg'));
        self::assertContains($number, $numbers('ORDER'));
        self::assertContains($number, $numbers('reg order'));
        self::assertNotContains($number, $numbers('reg nomatch'));
        self::assertNotContains($number, $numbers('zzzqqq'));
        self::assertNotContains($number, $numbers('%'));
        self::assertContains($number, $numbers('   '));
    }

    public function testProcessOrderAddsNoteWhenFulfilmentIsRedispatched(): void
    {
        $created = $this->post($this->payload());

        $this->client->request('POST', '/api/orders/' . $created['orderNumber'] . '/process', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt(self::REGISTER_EMAIL),
        ]);

        self::assertResponseIsSuccessful();
        $notes = $this->connection->fetchFirstColumn(
            "SELECT n.content FROM order_note n JOIN customer_order o ON o.id = n.order_id WHERE o.order_number = :n AND n.content LIKE 'Orderverwerking opnieuw gestart%'",
            ['n' => $created['orderNumber']],
        );
        self::assertCount(1, $notes);
        self::assertStringContainsString('opnieuw gestart door', $notes[0]);
    }

    public function testOrderNoteIsStoredAndReturned(): void
    {
        $created = $this->post($this->payload(['note' => '  Birthday group, seat together  ']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Birthday group, seat together', $created['note']);
        self::assertSame('Birthday group, seat together', $this->get('/api/orders/' . $created['orderNumber'])['note']);

        // Separate from the audit notes: no extra OrderNote was written for it.
        $audit = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM order_note n JOIN customer_order o ON o.id = n.order_id WHERE o.order_number = :n AND n.content LIKE '%Birthday%'",
            ['n' => $created['orderNumber']],
        );
        self::assertSame(0, $audit);

        $item = current(array_filter(
            $this->get('/api/orders?limit=100')['items'],
            static fn (array $i): bool => $i['orderNumber'] === $created['orderNumber'],
        ));
        self::assertSame('Birthday group, seat together', $item['note']);

        self::assertNull($this->post($this->payload())['note']);

        $this->post($this->payload(['note' => str_repeat('x', 1001)]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCustomerEmailIsOptional(): void
    {
        $customer = ['firstName' => 'Walk', 'lastName' => 'NoEmail'];
        $first = $this->post($this->payload(['customer' => $customer]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($first['customerEmail']);
        self::assertSame('completed', $first['status']);

        // An empty string counts as absent; a second email-less customer must not collide on the unique index.
        $second = $this->post($this->payload(['customer' => $customer + ['email' => '']]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($second['customerEmail']);

        $detail = $this->get('/api/orders/' . $first['orderNumber']);
        self::assertNull($detail['customerEmail']);
    }

    public function testOrderListSearchReportsFilteredTotal(): void
    {
        $this->post($this->payload());

        $list = $this->get('/api/orders?search=zzzqqq');

        self::assertResponseIsSuccessful();
        self::assertSame([], $list['items']);
        self::assertSame(0, $list['total']);
    }

    public function testPercentDiscountIsApplied(): void
    {
        $data = $this->post($this->payload([
            'discount' => ['type' => 'percent', 'amount' => 10, 'reason' => 'Staff'],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(1800, $data['totalCents']);
        self::assertSame(200, $data['discountGrossCents']);
        self::assertSame('percent', $data['discount']['type']);
        self::assertSame(10, $data['discount']['amount']);
        self::assertSame('Staff', $data['discount']['reason']);
        self::assertSame(1800, $data['subtotalNetCents'] + $data['taxCents']);
    }

    public function testFractionalPercentDiscountIsApplied(): void
    {
        $data = $this->post($this->payload([
            'discount' => ['type' => 'percent', 'amount' => 12.5, 'reason' => 'Group'],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(250, $data['discountGrossCents']);
        self::assertSame(1750, $data['totalCents']);
        self::assertSame(12.5, $data['discount']['amount']);
    }

    public function testFixedDiscountIsApplied(): void
    {
        $data = $this->post($this->payload([
            'discount' => ['type' => 'fixed', 'amount' => 500, 'reason' => 'Goodwill'],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(1500, $data['totalCents']);
        self::assertSame('fixed', $data['discount']['type']);
        self::assertSame(500, $data['discount']['amount']);
    }

    public function testFullDiscountCompletesWithoutPayment(): void
    {
        $data = $this->post($this->payload([
            'discount' => ['type' => 'percent', 'amount' => 100, 'reason' => 'Comp ticket'],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('completed', $data['status']);
        self::assertSame(0, $data['totalCents']);
        self::assertNull($data['paymentMethod']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDiscounts(): iterable
    {
        yield 'percent above 100%' => [['discount' => ['type' => 'percent', 'amount' => 100.5, 'reason' => 'x']]];
        yield 'percent with three decimals' => [['discount' => ['type' => 'percent', 'amount' => 12.345, 'reason' => 'x']]];
        yield 'fixed with fractional cents' => [['discount' => ['type' => 'fixed', 'amount' => 10.5, 'reason' => 'x']]];
        yield 'zero amount' => [['discount' => ['type' => 'fixed', 'amount' => 0, 'reason' => 'x']]];
        yield 'missing reason' => [['discount' => ['type' => 'fixed', 'amount' => 100, 'reason' => '']]];
        yield 'unknown type' => [['discount' => ['type' => 'bogus', 'amount' => 100, 'reason' => 'x']]];
        yield 'fixed above subtotal' => [['discount' => ['type' => 'fixed', 'amount' => 2001, 'reason' => 'x']]];
        yield 'coupon and discount combined' => [[
            'couponCode' => 'ANYTHING',
            'discount' => ['type' => 'fixed', 'amount' => 100, 'reason' => 'x'],
        ]];
    }

    /**
     * @param array<string, mixed> $override
     *
     */
    #[DataProvider('invalidDiscounts')]
    public function testInvalidDiscountIsRejected(array $override): void
    {
        $this->post($this->payload($override));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(self::INITIAL_STOCK, $this->stock(), 'rejected order must not take stock');
    }

    public function testUnknownCouponIsRejected(): void
    {
        $this->post($this->payload(['couponCode' => 'DOES-NOT-EXIST']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testValidationErrors(): void
    {
        $this->post($this->payload(['items' => []]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        foreach (['bitcoin', 'pin', 'invoice', 'online'] as $method) {
            $this->post($this->payload(['paymentMethod' => $method]));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $method);
        }

        $this->post($this->payload(['customer' => ['email' => 'not-an-email', 'firstName' => 'A', 'lastName' => 'B']]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUnknownTicketTypeReturns404(): void
    {
        $this->post($this->payload(['items' => [['ticketTypeId' => '018f0000-0000-7000-8000-000000000000', 'quantity' => 1]]]));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testInsufficientStockReturns409AndKeepsStock(): void
    {
        $this->post($this->payload(['items' => [['ticketTypeId' => $this->ticketTypeId, 'quantity' => self::INITIAL_STOCK + 1]]]));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(self::INITIAL_STOCK, $this->stock());
    }

    public function testIdempotencyKeyReturnsTheSameOrder(): void
    {
        $key = 'register-order-test-' . bin2hex(random_bytes(4));

        $first = $this->post($this->payload(), idempotencyKey: $key);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $second = $this->post($this->payload(), idempotencyKey: $key);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSame($first['orderNumber'], $second['orderNumber']);
        self::assertSame(self::INITIAL_STOCK - 2, $this->stock(), 'replay must not take stock twice');
    }

    public function testOverlongIdempotencyKeyIsRejected(): void
    {
        $this->post($this->payload(), idempotencyKey: str_repeat('a', 65));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCancelReleasesStockVoidsTicketsAndIsIdempotent(): void
    {
        $created = $this->post($this->payload());
        self::assertSame(self::INITIAL_STOCK - 2, $this->stock());

        $data = $this->delete($created['orderNumber']);

        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $data['status']);
        self::assertSame(self::INITIAL_STOCK, $this->stock());
        $statuses = $this->connection->fetchFirstColumn(
            'SELECT t.status FROM ticket t JOIN customer_order o ON o.id = t.order_id WHERE o.order_number = :n',
            ['n' => $created['orderNumber']],
        );
        self::assertSame(['void', 'void'], $statuses);
        $notes = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM order_note n JOIN customer_order o ON o.id = n.order_id WHERE o.order_number = :n AND n.content LIKE 'Bestelling geannuleerd%'",
            ['n' => $created['orderNumber']],
        );
        self::assertSame(1, $notes);

        $this->delete($created['orderNumber']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::INITIAL_STOCK, $this->stock(), 'second cancel must not release stock again');
    }

    public function testCancelRefusesCheckedInTickets(): void
    {
        $created = $this->post($this->payload());
        $this->connection->executeStatement(
            "UPDATE ticket SET status = 'checked_in' WHERE order_id = (SELECT id FROM customer_order WHERE order_number = :n)",
            ['n' => $created['orderNumber']],
        );

        $this->delete($created['orderNumber']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(self::INITIAL_STOCK - 2, $this->stock());
    }

    public function testCancelRefusesNonRegisterOrders(): void
    {
        $created = $this->post($this->payload());
        $this->connection->executeStatement(
            "UPDATE customer_order SET source = 'web' WHERE order_number = :n",
            ['n' => $created['orderNumber']],
        );

        $this->delete($created['orderNumber']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testCancelAuthAndNotFound(): void
    {
        $this->delete('NOPE-1', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->delete('NOPE-1', self::PLAIN_EMAIL);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->delete('NOPE-1');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
