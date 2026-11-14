<?php

declare(strict_types=1);

namespace App\Tests\Controller\Register;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderSource;
use App\Enum\OrderStatus;
use App\Twig\Components\RegisterOrderTable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RegisterOrdersTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const USER_EMAIL = 'register-orders-user@example.com';
    private const CUSTOMER_EMAIL = 'register-orders-customer@example.com';
    private const WEB = 'RO-WEB-1';
    private const TILL = 'RO-TILL-1';
    private const PENDING = 'RO-PEND-1';

    private string $host;
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = (string) ($_SERVER['REGISTER_HOST'] ?? 'register.localhost');
        $this->client = static::createClient(server: ['HTTP_HOST' => $this->host]);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();

        $this->user = new User(self::USER_EMAIL, 'unused', ['ROLE_REGISTER']);
        $this->user->name = 'Kassa Test';
        $this->em->persist($this->user);

        $customer = new Customer(self::CUSTOMER_EMAIL, 'Jan', 'Jansen');
        $this->em->persist($customer);
        $fixtures = [
            self::WEB => [OrderSource::WEB, OrderStatus::COMPLETED],
            self::TILL => [OrderSource::REGISTER, OrderStatus::COMPLETED],
            self::PENDING => [OrderSource::WEB, OrderStatus::PENDING],
        ];
        foreach ($fixtures as $number => [$source, $status]) {
            $order = new Order($number, $customer, 900);
            $order->source = $source;
            $order->status = $status;
            $this->em->persist($order);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testOrdersPageShowsRowsWithSourceAndStatusIcons(): void
    {
        $this->client->loginUser($this->user, 'register');
        $crawler = $this->client->request('GET', '/bestellingen');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', self::WEB);
        self::assertSelectorTextContains('table', 'Jan Jansen');
        $webRow = $crawler->filter('tr:contains("' . self::WEB . '")');
        self::assertCount(1, $webRow->filter('[data-tooltip="Via internet"]'));
        self::assertCount(1, $webRow->filter('[data-tooltip="Voltooid"]'));
        $tillRow = $crawler->filter('tr:contains("' . self::TILL . '")');
        self::assertCount(1, $tillRow->filter('[data-tooltip="Via kassa"]'));
        self::assertCount(0, $tillRow->filter('[data-tooltip="Via internet"]'));
        $pendingRow = $crawler->filter('tr:contains("' . self::PENDING . '")');
        self::assertCount(1, $pendingRow->filter('[data-tooltip="In afwachting"]'));
    }

    public function testSearchFiltersByOrderNumber(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);
        $component->set('search', self::TILL);

        $html = (string) $component->render();
        self::assertStringContainsString(self::TILL, $html);
        self::assertStringNotContainsString(self::WEB, $html);
    }

    public function testOrdersArePaginatedThroughTheUxPaginator(): void
    {
        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => self::CUSTOMER_EMAIL]);
        $perPage = RegisterOrderTable::PER_PAGE;
        $total = $perPage + 1;
        for ($i = 1; $i <= $total; ++$i) {
            $this->em->persist(new Order(sprintf('RO-PG-%02d', $i), $customer, 900));
        }
        $this->em->flush();

        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);
        $component->set('search', 'RO-PG');

        $first = (string) $component->render();
        self::assertSame($perPage, substr_count($first, 'whitespace-nowrap">RO-PG-'));
        self::assertStringContainsString(sprintf('Toont 1-%d van %d', $perPage, $total), $first);
        self::assertStringContainsString('aria-label="Volgende pagina"', $first);

        $component->call('goToPage', ['page' => 2]);
        $second = (string) $component->render();
        self::assertSame(1, substr_count($second, 'whitespace-nowrap">RO-PG-'));
        self::assertStringContainsString(sprintf('Toont %1$d-%1$d van %1$d', $total), $second);
    }

    public function testNoteCanBeAddedThroughTheModal(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);

        $component->call('openNotes', ['orderNumber' => self::WEB]);
        self::assertStringContainsString('Notities voor bestelling', (string) $component->render());

        $component->set('noteContent', '   ');
        $component->call('addNote');
        self::assertStringContainsString('Vul een notitie in.', (string) $component->render());

        $component->set('noteContent', 'Klant komt later');
        $component->call('addNote');
        self::assertStringContainsString('Klant komt later', (string) $component->render());
    }

    public function testCancelAsksForConfirmationThenCancelsTheOrder(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);

        $component->call('askCancel', ['orderNumber' => self::PENDING]);
        self::assertStringContainsString('Bestelling annuleren?', (string) $component->render());
        $this->assertStatus(self::PENDING, OrderStatus::PENDING);

        $component->call('confirmCancel');
        self::assertStringContainsString('is geannuleerd', (string) $component->render());
        $this->assertStatus(self::PENDING, OrderStatus::CANCELLED);
    }

    public function testResendMailRecordsANoteForCompletedOrders(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);

        $component->call('resendMail', ['orderNumber' => self::WEB]);
        self::assertStringContainsString('opnieuw verstuurd', (string) $component->render());

        $component->call('openNotes', ['orderNumber' => self::WEB]);
        self::assertStringContainsString(
            'Orderverwerking opnieuw gestart door Kassa Test',
            (string) $component->render(),
        );
    }

    public function testResendMailIsRefusedForUncompletedOrders(): void
    {
        $this->client->loginUser($this->user, 'register');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);

        $component->call('resendMail', ['orderNumber' => self::PENDING]);

        self::assertStringContainsString(
            'alleen worden verstuurd voor voltooide bestellingen',
            (string) $component->render(),
        );
    }

    public function testLiveComponentActionsAreDeniedOnOtherHostsWithoutTheRegisterRole(): void
    {
        // Live components answer on every host, so the component guards itself
        // (the register firewall only covers the register host).
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $component = $this->createLiveComponent(name: 'RegisterOrderTable', client: $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('askCancel', ['orderNumber' => self::PENDING]);
    }

    private function assertStatus(string $orderNumber, OrderStatus $expected): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => $orderNumber]);
        self::assertSame($expected, $order?->status);
    }

    private function cleanUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->clear();
        foreach ([self::WEB, self::TILL, self::PENDING] as $number) {
            $order = $this->em->getRepository(Order::class)->findOneBy(['orderNumber' => $number]);
            if ($order !== null) {
                $this->em->remove($order);
            }
        }
        foreach ($this->em->getRepository(Order::class)->findBy([]) as $order) {
            if (str_starts_with($order->orderNumber, 'RO-PG-')) {
                $this->em->remove($order);
            }
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::USER_EMAIL]);
        if ($user !== null) {
            $this->em->remove($user);
        }
        $this->em->flush();

        $customer = $this->em->getRepository(Customer::class)->findOneBy(['email' => self::CUSTOMER_EMAIL]);
        if ($customer !== null) {
            $this->em->remove($customer);
            $this->em->flush();
        }
    }
}
