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
        $orders = '(SELECT o.id FROM customer_order o JOIN customer c ON c.id = o.customer_id WHERE c.email = :email)';
        $params = ['email' => self::CUSTOMER_EMAIL];

        foreach (['ticket', 'stock_reservation', 'payment', 'order_note', 'order_line_item'] as $table) {
            $this->connection->executeStatement("DELETE FROM {$table} WHERE order_id IN {$orders}", $params);
        }
        $this->connection->executeStatement("DELETE FROM customer_order WHERE id IN {$orders}", $params);
        $this->connection->executeStatement('DELETE FROM customer WHERE email = :email', $params);
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

    public function testPercentDiscountIsApplied(): void
    {
        $data = $this->post($this->payload([
            'discount' => ['type' => 'percent', 'amount' => 1000, 'reason' => 'Staff'],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(1800, $data['totalCents']);
        self::assertSame(200, $data['discountGrossCents']);
        self::assertSame('percent', $data['discount']['type']);
        self::assertSame('Staff', $data['discount']['reason']);
        self::assertSame(1800, $data['subtotalNetCents'] + $data['taxCents']);
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
            'discount' => ['type' => 'percent', 'amount' => 10000, 'reason' => 'Comp ticket'],
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
        yield 'percent above 100%' => [['discount' => ['type' => 'percent', 'amount' => 10001, 'reason' => 'x']]];
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

        $this->post($this->payload(['paymentMethod' => 'bitcoin']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

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
}
