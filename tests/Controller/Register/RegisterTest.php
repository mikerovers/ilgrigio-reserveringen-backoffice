<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RegisterTest extends WebTestCase
{
    private const REGISTER_EMAIL = 'register-test-register@example.com';
    private const CHECK_IN_EMAIL = 'register-test-checkin@example.com';
    private const ADMIN_EMAIL = 'register-test-admin@example.com';

    private string $host;
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $registerUser;
    private User $checkInUser;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = (string) ($_SERVER['REGISTER_HOST'] ?? 'register.localhost');
        $this->client = static::createClient(server: ['HTTP_HOST' => $this->host]);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $this->registerUser = new User(self::REGISTER_EMAIL, 'unused', ['ROLE_REGISTER']);
        $this->registerUser->name = 'Kassa 1';
        $this->checkInUser = new User(self::CHECK_IN_EMAIL, 'unused', ['ROLE_CHECK_IN']);
        $this->checkInUser->name = 'Balie 1';
        $this->adminUser = new User(self::ADMIN_EMAIL, 'unused', ['ROLE_ADMIN']);
        $this->adminUser->name = 'Admin';

        foreach ([$this->registerUser, $this->checkInUser, $this->adminUser] as $user) {
            $this->em->persist($user);
        }
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

        self::assertResponseRedirects('http://' . $this->host . '/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testManifestIsPublicAndDescribesTheApp(): void
    {
        $this->client->request('GET', '/manifest.webmanifest');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/manifest+json');
        $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Kassa', $manifest['name']);
        self::assertSame('/', $manifest['start_url']);
    }

    public function testServiceWorkerIsPublicAndServedAsJavaScript(): void
    {
        $this->client->request('GET', '/sw.js');

        self::assertResponseIsSuccessful();
        $contentType = (string) $this->client->getResponse()->headers->get('Content-Type');
        self::assertStringContainsString('text/javascript', $contentType);
        self::assertStringContainsString('kassa-shell-v1', (string) $this->client->getResponse()->getContent());
    }

    public function testLoginPageLinksTheManifest(): void
    {
        $this->client->request('GET', '/login');

        self::assertSelectorExists('link[rel="manifest"][href="/manifest.webmanifest"]');
    }

    public function testRegisterUserSeesTheShellWithOperatorAndLogout(): void
    {
        $this->client->loginUser($this->registerUser, 'register');
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', 'Kassa');
        self::assertSelectorTextContains('header', 'Kassa 1');
        self::assertSelectorExists('header a[href="/logout"]');
    }

    public function testMenuListsAllSectionsAndMarksTheCurrentOne(): void
    {
        $this->client->loginUser($this->registerUser, 'register');
        $this->client->request('GET', '/bestellingen');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'nav[aria-label="Hoofdmenu"] a');
        self::assertSelectorExists('nav a[href="/"]');
        self::assertSelectorExists('nav a[href="/kortingscodes"]');
        self::assertSelectorCount(1, 'nav a[aria-current="page"]');
        self::assertSelectorTextContains('nav a[aria-current="page"]', 'Bestellingen');
    }

    public function testCouponsPageRequiresRegisterRole(): void
    {
        $this->client->loginUser($this->checkInUser, 'register');
        $this->client->request('GET', '/kortingscodes');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminInheritsRegisterAccess(): void
    {
        $this->client->loginUser($this->adminUser, 'register');
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testCheckInOnlyUserIsForbidden(): void
    {
        $this->client->loginUser($this->checkInUser, 'register');
        $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLoggedInUserIsRedirectedAwayFromLogin(): void
    {
        $this->client->loginUser($this->registerUser, 'register');
        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testLogoutSendsTheUserBackToLogin(): void
    {
        $this->client->loginUser($this->registerUser, 'register');
        $this->client->request('GET', '/logout');

        self::assertResponseRedirects('http://' . $this->host . '/login');
    }

    public function testRegisterRoutesAreNotServedOnTheScannerHost(): void
    {
        $this->client->request('GET', '/manifest.webmanifest', server: ['HTTP_HOST' => 'scanner.localhost']);

        // The scanner host has its own manifest, not the register one.
        $manifest = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Scanbalie', $manifest['name']);
    }

    private function cleanUp(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.email IN (:e)')
            ->setParameter('e', [self::REGISTER_EMAIL, self::CHECK_IN_EMAIL, self::ADMIN_EMAIL])->execute();
    }
}
