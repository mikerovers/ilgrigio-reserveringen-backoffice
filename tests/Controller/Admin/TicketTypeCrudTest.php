<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Event;
use App\Entity\TicketType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating a ticket type from an event's filtered list must attach it to that
 * event, not to the most recent one.
 */
final class TicketTypeCrudTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'ticket-type-crud-test@example.com';
    private const EVENT_PREFIX = 'Ticket Type Crud Test';

    public function testNewButtonKeepsEventFilterSoTicketTypeLandsOnThatEvent(): void
    {
        $client = static::createClient(server: ['HTTP_HOST' => 'admin.localhost']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp($em);

        $admin = new User(self::ADMIN_EMAIL, 'unused', ['ROLE_ADMIN']);
        $em->persist($admin);

        // Make the target event the older one, so the latest-event fallback would pick the wrong event.
        $target = new Event(self::EVENT_PREFIX . ' target', new \DateTimeImmutable('2030-01-01 20:00:00'));
        $latest = new Event(self::EVENT_PREFIX . ' latest', new \DateTimeImmutable('2031-01-01 20:00:00'));
        $em->persist($target);
        $em->persist($latest);
        $em->flush();

        try {
            $client->loginUser($admin, 'main');

            $crawler = $client->request('GET', '/ticket-type', ['event' => (string) $target->id]);
            self::assertResponseIsSuccessful();

            $href = $crawler->filter('a.action-new')->attr('href');
            self::assertNotNull($href);
            self::assertStringContainsString('event=' . $target->id, (string) $href);

            $crawler = $client->request('GET', (string) $href);
            self::assertResponseIsSuccessful();

            $selected = $crawler->filter('select[name$="[event]"] option[selected]');
            self::assertCount(1, $selected);
            self::assertSame((string) $target->id, $selected->attr('value'));
        } finally {
            $this->cleanUp($em);
        }
    }

    private function cleanUp(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\TicketType t WHERE t.name LIKE :p')
            ->setParameter('p', self::EVENT_PREFIX . '%')
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\Event e WHERE e.name LIKE :p')
            ->setParameter('p', self::EVENT_PREFIX . '%')
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :e')
            ->setParameter('e', self::ADMIN_EMAIL)
            ->execute();
    }
}
