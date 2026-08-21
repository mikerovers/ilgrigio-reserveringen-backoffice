<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class JwtLoginTest extends WebTestCase
{
    private const TEST_EMAIL = 'jwt-login-test@example.com';
    private const TEST_PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->removeTestUser();

        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User(self::TEST_EMAIL, '', ['ROLE_ADMIN']);
        $user->password = $hasher->hashPassword($user, self::TEST_PASSWORD);

        $em->persist($user);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();

        parent::tearDown();
    }

    private function removeTestUser(): void
    {
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $existing = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);

        if ($existing !== null) {
            $em->remove($existing);
            $em->flush();
        }
    }

    public function testLoginReturnsJwtToken(): void
    {
        $this->client->request('POST', '/api/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => self::TEST_EMAIL,
            'password' => self::TEST_PASSWORD,
        ]));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
        self::assertArrayHasKey('refresh_token', $data);
        self::assertNotEmpty($data['refresh_token']);
    }

    public function testLoginFailsWithBadCredentials(): void
    {
        $this->client->request('POST', '/api/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => self::TEST_EMAIL,
            'password' => 'wrong-password',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLoginFailsWithUnknownEmail(): void
    {
        $this->client->request('POST', '/api/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
