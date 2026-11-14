<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\Coupon;
use App\Entity\User;
use App\Enum\DiscountType;
use App\Twig\Components\RegisterCouponTable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RegisterCouponsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const USER_EMAIL = 'register-coupons-user@example.com';
    private const PREFIX = 'RCTEST';

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
        $this->user->name = 'Kassa Test';
        $this->em->persist($this->user);

        $fixed = new Coupon(self::PREFIX . 'FIXED', DiscountType::FIXED_CART, 1200);
        $percent = new Coupon(self::PREFIX . 'PCT', DiscountType::PERCENT, 1050);
        $percent->usageLimit = 5;
        $expired = new Coupon(self::PREFIX . 'OLD', DiscountType::BY_TICKET, 2);
        $expired->expiresAt = new \DateTimeImmutable('2020-01-01 10:00:00');
        $off = new Coupon(self::PREFIX . 'OFF', DiscountType::PERCENT, 500);
        $off->active = false;
        foreach ([$fixed, $percent, $expired, $off] as $coupon) {
            $this->em->persist($coupon);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testPageShowsCouponsWithAmountUsageAndStatus(): void
    {
        $this->client->loginUser($this->user, 'register');
        $crawler = $this->client->request('GET', '/kortingscodes');

        self::assertResponseIsSuccessful();
        $fixed = $crawler->filter('tr:contains("' . self::PREFIX . 'FIXED")');
        self::assertStringContainsString('€ 12,00', $fixed->text());
        self::assertCount(1, $fixed->filter('[data-tooltip="Geldig"]'));
        $percent = $crawler->filter('tr:contains("' . self::PREFIX . 'PCT")');
        self::assertStringContainsString('10,5%', $percent->text());
        self::assertStringContainsString('0 / 5', $percent->text());
        $expired = $crawler->filter('tr:contains("' . self::PREFIX . 'OLD")');
        self::assertStringContainsString('2 tickets gratis', $expired->text());
        self::assertStringContainsString('01-01-2020', $expired->text());
        self::assertCount(1, $expired->filter('[data-tooltip="Verlopen"]'));
        self::assertCount(1, $crawler->filter('tr:contains("' . self::PREFIX . 'OFF")')->filter('[data-tooltip="Ongeldig"]'));
    }

    public function testSearchFiltersByCode(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterCouponTable', client: $this->client);
        $component->set('search', self::PREFIX . 'fixed');

        $html = (string) $component->render();
        self::assertStringContainsString(self::PREFIX . 'FIXED', $html);
        self::assertStringNotContainsString(self::PREFIX . 'PCT', $html);
    }

    public function testCouponsArePaginated(): void
    {
        $perPage = RegisterCouponTable::PER_PAGE;
        for ($i = 1; $i <= $perPage; ++$i) {
            $this->em->persist(new Coupon(sprintf('%sPG%02d', self::PREFIX, $i), DiscountType::PERCENT, 100));
        }
        $this->em->flush();

        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterCouponTable', client: $this->client);
        $component->set('search', self::PREFIX . 'PG');
        self::assertSame($perPage, substr_count((string) $component->render(), 'whitespace-nowrap">' . self::PREFIX . 'PG'));

        $component->set('search', self::PREFIX);
        $component->call('goToPage', ['page' => 2]);
        self::assertStringContainsString('aria-label="Vorige pagina"', (string) $component->render());
    }

    public function testDeactivateAsksForConfirmationThenInvalidatesTheCoupon(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterCouponTable', client: $this->client);
        $id = (string) $this->findCoupon(self::PREFIX . 'FIXED')->id;

        $component->call('askDeactivate', ['id' => $id]);
        self::assertStringContainsString('Kortingscode ongeldig maken?', (string) $component->render());
        self::assertTrue($this->findCoupon(self::PREFIX . 'FIXED')->active);

        $component->call('confirmDeactivate');
        self::assertStringContainsString('is ongeldig gemaakt', (string) $component->render());
        self::assertFalse($this->findCoupon(self::PREFIX . 'FIXED')->active);
    }

    public function testAskingToDeactivateAnInactiveOrUnknownCouponOpensNothing(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterCouponTable', client: $this->client);

        $component->call('askDeactivate', ['id' => (string) $this->findCoupon(self::PREFIX . 'OFF')->id]);
        self::assertStringNotContainsString('Kortingscode ongeldig maken?', (string) $component->render());

        $component->call('askDeactivate', ['id' => 'not-a-uuid']);
        self::assertStringNotContainsString('Kortingscode ongeldig maken?', (string) $component->render());
    }

    public function testActionsAreDeniedWithoutTheRegisterRole(): void
    {
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $component = $this->createLiveComponent(name: 'RegisterCouponTable', client: $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('askDeactivate', ['id' => (string) $this->findCoupon(self::PREFIX . 'FIXED')->id]);
    }

    private function findCoupon(string $code): Coupon
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();

        return $this->em->getRepository(Coupon::class)->findOneBy(['code' => $code]);
    }

    private function cleanUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        foreach ($this->em->getRepository(Coupon::class)->findAll() as $coupon) {
            if (str_starts_with($coupon->code, self::PREFIX)) {
                $this->em->remove($coupon);
            }
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        if ($user !== null) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }
}
