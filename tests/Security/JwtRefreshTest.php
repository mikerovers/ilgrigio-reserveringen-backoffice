<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class JwtRefreshTest extends WebTestCase
{
    private const TEST_EMAIL = 'jwt-refresh-test@example.com';
    private const TEST_PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->removeTestData();

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
        $this->removeTestData();

        parent::tearDown();
    }

    private function removeTestData(): void
    {
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $existingToken = $em->getRepository(RefreshToken::class)->findOneBy(['username' => self::TEST_EMAIL]);
        if ($existingToken !== null) {
            $em->remove($existingToken);
            $em->flush();
        }

        $existingUser = $em->getRepository(User::class)->findOneBy(['email' => self::TEST_EMAIL]);
        if ($existingUser !== null) {
            $em->remove($existingUser);
            $em->flush();
        }
    }

    private function obtainRefreshToken(): string
    {
        $this->client->request('POST', '/api/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => self::TEST_EMAIL,
            'password' => self::TEST_PASSWORD,
        ]));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['refresh_token'];
    }

    public function testRefreshIssuesNewJwtToken(): void
    {
        $refreshToken = $this->obtainRefreshToken();

        $this->client->request('POST', '/api/token/refresh', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['refresh_token' => $refreshToken]));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
        self::assertArrayHasKey('refresh_token', $data);
    }

    public function testRefreshFailsWithInvalidToken(): void
    {
        $this->client->request('POST', '/api/token/refresh', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['refresh_token' => 'not-a-real-refresh-token']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshFailsWithNoToken(): void
    {
        $this->client->request('POST', '/api/token/refresh', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
