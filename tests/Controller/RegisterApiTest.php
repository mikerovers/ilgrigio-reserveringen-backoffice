<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Coupon;
use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderNote;
use App\Entity\User;
use App\Enum\DiscountType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterApiTest extends WebTestCase
{
    private const REGISTER_EMAIL = 'register-api-test@example.com';
    private const ADMIN_EMAIL = 'register-api-admin@example.com';
    private const PLAIN_EMAIL = 'register-api-plain@example.com';
    private const PASSWORD = 'correct-horse-battery-staple';
    private const ORDER_NUMBER = 'REGAPI-1';
    private const COUPON_CODE = 'REGAPI-COUPON';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([self::REGISTER_EMAIL => ['ROLE_REGISTER'], self::ADMIN_EMAIL => ['ROLE_ADMIN'], self::PLAIN_EMAIL => []] as $email => $roles) {
            $user = new User($email, '', $roles);
            $user->password = $hasher->hashPassword($user, self::PASSWORD);
            $user->name = 'Test ' . $email;
            $this->em->persist($user);
        }

        $customer = new Customer('regapi-customer@example.com', 'Reg', 'Api');
        $order = new Order(self::ORDER_NUMBER, $customer, 900);
        $this->em->persist($customer);
        $this->em->persist($order);
        $this->em->persist(new OrderNote($order, 'first note'));

        $this->em->persist(new Coupon(self::COUPON_CODE, DiscountType::PERCENT, 1000));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        // Requests reboot the kernel, so resolve the entity manager of the
        // current container instead of keeping the one from setUp().
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();

        foreach ([self::REGISTER_EMAIL, self::ADMIN_EMAIL, self::PLAIN_EMAIL] as $email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }

        $order = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => self::ORDER_NUMBER]);
        if ($order !== null) {
            $this->em->remove($order);
        }

        $coupon = $this->em->getRepository(Coupon::class)->findOneBy(['code' => self::COUPON_CODE]);
        if ($coupon !== null) {
            $this->em->remove($coupon);
        }

        $this->em->flush();

        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => 'regapi-customer@example.com']);
        if ($customer !== null) {
            $this->em->remove($customer);
            $this->em->flush();
        }
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

    /** @return array<string, mixed> */
    private function call(string $method, string $uri, ?string $email = self::REGISTER_EMAIL, ?array $body = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($email !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwt($email);
        }
        $this->client->request($method, $uri, server: $server, content: $body !== null ? (string) json_encode($body) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testEndpointsRequireJwt(): void
    {
        foreach (['/api/orders', '/api/coupons', '/api/orders/' . self::ORDER_NUMBER . '/notes'] as $uri) {
            $this->call('GET', $uri, null);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
    }

    public function testMeReturnsTheLoggedInUsersName(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::REGISTER_EMAIL]);
        $user->name = 'Rita Register';
        $this->em->flush();

        $data = $this->call('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('Rita Register', $data['name']);
        self::assertSame(self::REGISTER_EMAIL, $data['email']);
        self::assertContains('ROLE_REGISTER', $data['roles']);
    }

    public function testMeWorksForAnyAuthenticatedUserAndRequiresJwt(): void
    {
        $data = $this->call('GET', '/api/me', self::PLAIN_EMAIL);
        self::assertResponseIsSuccessful();
        self::assertSame('Test ' . self::PLAIN_EMAIL, $data['name']);

        $this->call('GET', '/api/me', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEndpointsRequireRegisterRole(): void
    {
        foreach (['/api/orders', '/api/coupons', '/api/orders/' . self::ORDER_NUMBER . '/notes'] as $uri) {
            $this->call('GET', $uri, self::PLAIN_EMAIL);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }
    }

    public function testAdminCanCallRegisterEndpoints(): void
    {
        foreach (['/api/orders', '/api/coupons', '/api/events', '/api/orders/' . self::ORDER_NUMBER . '/notes'] as $uri) {
            $this->call('GET', $uri, self::ADMIN_EMAIL);
            self::assertResponseIsSuccessful();
        }
    }

    public function testOrdersArePaginatedAndOrderedByCreatedAt(): void
    {
        $data = $this->call('GET', '/api/orders?limit=1&page=1&orderDirection=ASC');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['limit']);
        self::assertCount(1, $data['items']);
        self::assertGreaterThanOrEqual(1, $data['total']);
        self::assertArrayHasKey('orderNumber', $data['items'][0]);
    }

    public function testNotesCanBeCreatedAndListed(): void
    {
        $url = '/api/orders/' . self::ORDER_NUMBER . '/notes';

        $created = $this->call('POST', $url, body: ['content' => 'second note']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('second note', $created['content']);

        $list = $this->call('GET', $url . '?orderDirection=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $list['total']);
        self::assertSame(['first note', 'second note'], array_column($list['items'], 'content'));
    }

    public function testNoteRejectsBlankContentAndUnknownOrder(): void
    {
        $this->call('POST', '/api/orders/' . self::ORDER_NUMBER . '/notes', body: ['content' => '  ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->call('GET', '/api/orders/NOPE/notes');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCouponsAreSearchableByCode(): void
    {
        $data = $this->call('GET', '/api/coupons?search=regapi-cou');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['total']);
        self::assertSame(self::COUPON_CODE, $data['items'][0]['code']);

        $data = $this->call('GET', '/api/coupons?search=zzz-no-match');
        self::assertSame(0, $data['total']);
    }

    public function testPutDeactivatesCouponIdempotently(): void
    {
        $id = $this->call('GET', '/api/coupons?search=' . self::COUPON_CODE)['items'][0]['id'];

        foreach ([1, 2] as $_) {
            $data = $this->call('PUT', '/api/coupons/' . $id . '/deactivate');
            self::assertResponseIsSuccessful();
            self::assertFalse($data['active']);
        }
    }

    public function testEventsExposeTicketTypesAndSharedStocks(): void
    {
        $data = $this->call('GET', '/api/events?includePast=true&limit=1');
        self::assertResponseIsSuccessful();

        foreach ($data['items'] as $item) {
            self::assertArrayHasKey('ticketTypes', $item);
            self::assertArrayHasKey('sharedStocks', $item);
            foreach ($item['sharedStocks'] as $pool) {
                self::assertArrayHasKey('lowStockThreshold', $pool);
                self::assertArrayHasKey('lowStock', $pool);
            }
            foreach ($item['ticketTypes'] as $type) {
                self::assertArrayHasKey('lowStockThreshold', $type);
                self::assertArrayHasKey('lowStock', $type);
            }
        }
    }
}
