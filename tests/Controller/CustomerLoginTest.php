<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\LoginCode;
use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CustomerLoginTest extends WebTestCase
{
    private const ORDER_NUMBER = 'LOGINTEST-1';
    private const OTHER_ORDER_NUMBER = 'LOGINTEST-2';

    private string $email;
    private string $otherEmail;
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique per test: the request rate limiters keep state between tests and runs.
        $this->email = 'login-test-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->otherEmail = 'login-test-other-' . bin2hex(random_bytes(4)) . '@example.com';

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $this->createCustomerWithOrder($this->email, self::ORDER_NUMBER);
        $this->createCustomerWithOrder($this->otherEmail, self::OTHER_ORDER_NUMBER);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    public function testTicketAreaRedirectsAnonymousVisitorsToLogin(): void
    {
        $this->client->request('GET', '/mijn-tickets');

        self::assertResponseRedirects('/inloggen');
    }

    public function testCustomerAreaIsNotFoundWhenSwitchedOff(): void
    {
        // Env vars are resolved when the container is built, so boot a fresh kernel with the flag off.
        self::ensureKernelShutdown();
        $_SERVER['CUSTOMER_LOGIN_ENABLED'] = $_ENV['CUSTOMER_LOGIN_ENABLED'] = '0';
        putenv('CUSTOMER_LOGIN_ENABLED=0');

        try {
            $client = static::createClient();
            $client->request('GET', '/inloggen');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

            $client->request('POST', '/inloggen/code');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $_SERVER['CUSTOMER_LOGIN_ENABLED'] = $_ENV['CUSTOMER_LOGIN_ENABLED'] = '1';
            putenv('CUSTOMER_LOGIN_ENABLED=1');
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
        }
    }

    public function testStorefrontHasNoLoginLinkWhenSwitchedOff(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['CUSTOMER_LOGIN_ENABLED'] = $_ENV['CUSTOMER_LOGIN_ENABLED'] = '0';
        putenv('CUSTOMER_LOGIN_ENABLED=0');

        try {
            $client = static::createClient();
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('/inloggen', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('/mijn-tickets', (string) $client->getResponse()->getContent());
        } finally {
            $_SERVER['CUSTOMER_LOGIN_ENABLED'] = $_ENV['CUSTOMER_LOGIN_ENABLED'] = '1';
            putenv('CUSTOMER_LOGIN_ENABLED=1');
            self::ensureKernelShutdown();
            $this->client = static::createClient();
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
        }
    }

    public function testUnknownEmailGetsSameResponseButNoEmail(): void
    {
        $this->requestCode('nobody-' . $this->email);

        self::assertResponseRedirects('/inloggen/code');
        self::assertCount(0, $this->sentCodes());
    }

    public function testFullLoginFlowAndOrderOwnership(): void
    {
        $this->requestCode($this->email);
        self::assertResponseRedirects('/inloggen/code');

        $sent = $this->sentCodes();
        self::assertCount(1, $sent);
        self::assertMatchesRegularExpression('/^\d{6}$/', $sent[0]);

        // The code is stored hashed, never in plain text.
        $stored = $this->em->getRepository(LoginCode::class)->findOneBy(['email' => $this->email]);
        self::assertNotNull($stored);
        self::assertNotSame($sent[0], $stored->codeHash);

        // Wrong code is rejected.
        $this->submitCode('000000' === $sent[0] ? '111111' : '000000');
        self::assertResponseRedirects('/inloggen/code');

        // Right code logs in.
        $this->submitCode($sent[0]);
        self::assertResponseRedirects('/mijn-tickets');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', self::ORDER_NUMBER);
        self::assertSelectorTextNotContains('body', self::OTHER_ORDER_NUMBER);

        // Own order downloads as PDF; another customer's order is a 404.
        $this->client->request('GET', '/mijn-tickets/' . self::ORDER_NUMBER . '/pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->client->request('GET', '/mijn-tickets/' . self::OTHER_ORDER_NUMBER . '/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCodeRequestsArePerEmailRateLimited(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->requestCode($this->email);
            self::assertResponseRedirects('/inloggen/code');
        }

        $this->requestCode($this->email);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCodeCanOnlyBeUsedOnce(): void
    {
        $this->requestCode($this->email);
        $code = $this->sentCodes()[0];

        $this->submitCode($code);
        self::assertResponseRedirects('/mijn-tickets');

        $this->client->request('GET', '/uitloggen');
        $this->requestCode($this->email);
        $this->client->request('GET', '/inloggen/code');

        // The first code was consumed/invalidated by the new request.
        $this->submitCode($code);
        self::assertResponseRedirects('/inloggen/code');
    }

    public function testCodeIsBlockedAfterTooManyWrongAttempts(): void
    {
        $this->requestCode($this->email);
        $code = $this->sentCodes()[0];
        $wrong = '000000' === $code ? '111111' : '000000';

        for ($i = 0; $i < 5; ++$i) {
            $this->submitCode($wrong);
        }

        $this->submitCode($code);
        self::assertResponseRedirects('/inloggen/code');
    }

    private function requestCode(string $email): void
    {
        $crawler = $this->client->request('GET', '/inloggen');
        $form = $crawler->selectButton('Stuur me een code')->form(['email' => $email]);
        $this->client->submit($form);
    }

    private function submitCode(string $code): void
    {
        $crawler = $this->client->request('GET', '/inloggen/code');
        $form = $crawler->selectButton('Inloggen')->form(['code' => $code]);
        $this->client->submit($form);
    }

    /**
     * Codes mailed so far. The email transport is synchronous in the test
     * environment, so the handler has already rendered and "sent" each mail.
     *
     * @return list<string>
     */
    private function sentCodes(): array
    {
        $codes = [];
        foreach (self::getMailerMessages() as $message) {
            if (preg_match('/\b(\d{6})\b/', (string) $message->getTextBody(), $matches) === 1) {
                $codes[] = $matches[1];
            }
        }

        return $codes;
    }

    private function createCustomerWithOrder(string $email, string $orderNumber): void
    {
        $customer = new Customer($email, 'Test', 'Klant');
        $order = new Order($orderNumber, $customer, 900);
        $order->status = OrderStatus::COMPLETED;
        $customer->orders->add($order);

        $this->em->persist($customer);
        $this->em->persist($order);
        $this->em->flush();
    }

    private function cleanUp(): void
    {
        // Requests reboot the kernel, so resolve the entity manager of the
        // current container instead of keeping the one from setUp().
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();

        foreach ([self::ORDER_NUMBER, self::OTHER_ORDER_NUMBER] as $number) {
            $order = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
            if ($order !== null) {
                $this->em->remove($order);
            }
        }
        $this->em->flush();

        foreach ([$this->email, $this->otherEmail] as $email) {
            $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => $email]);
            if ($customer !== null) {
                $this->em->remove($customer);
            }
        }
        $this->em->createQuery('DELETE FROM ' . LoginCode::class . ' l WHERE l.email IN (:emails)')
            ->setParameter('emails', [$this->email, $this->otherEmail, 'nobody-' . $this->email])
            ->execute();
        $this->em->flush();
    }
}
