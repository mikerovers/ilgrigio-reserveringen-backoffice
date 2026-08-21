<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CheckInJwtAuthTest extends WebTestCase
{
    private const TEST_EMAIL = 'checkin-jwt-test@example.com';
    private const TEST_PASSWORD = 'correct-horse-battery-staple';

    private KernelBrowser $client;
    private string $eventId;

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

        $event = new Event('JWT Auth Test Event', new \DateTimeImmutable('+1 day'));
        $em->persist($event);

        $em->flush();

        $this->eventId = (string) $event->id;
    }

    protected function tearDown(): void
    {
        $this->removeTestUser();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $event = $em->getRepository(Event::class)->find($this->eventId);
        if ($event !== null) {
            $em->remove($event);
            $em->flush();
        }

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

    private function obtainJwt(): string
    {
        $this->client->request('POST', '/api/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'email' => self::TEST_EMAIL,
            'password' => self::TEST_PASSWORD,
        ]));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data['token'];
    }

    public function testCheckInAuthenticatesWithValidJwt(): void
    {
        $token = $this->obtainJwt();

        $this->client->request('POST', sprintf('/api/events/%s/check-in', $this->eventId), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['ticketCode' => 'DOES-NOT-EXIST']));

        // A valid JWT clears authentication; the controller then processes
        // the request normally and reports the ticket code wasn't found —
        // this proves auth passed (a 401 would mean it didn't).
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCheckInFailsWithInvalidJwt(): void
    {
        $this->client->request('POST', sprintf('/api/events/%s/check-in', $this->eventId), server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.token.here',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['ticketCode' => 'DOES-NOT-EXIST']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCheckInRejectsLegacyApiKeyAlone(): void
    {
        $this->client->request('POST', sprintf('/api/events/%s/check-in', $this->eventId), server: [
            'HTTP_X_API_KEY' => $_ENV['API_KEY'] ?? '',
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['ticketCode' => 'DOES-NOT-EXIST']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCheckInFailsWithNoCredentials(): void
    {
        $this->client->request('POST', sprintf('/api/events/%s/check-in', $this->eventId), server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['ticketCode' => 'DOES-NOT-EXIST']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
