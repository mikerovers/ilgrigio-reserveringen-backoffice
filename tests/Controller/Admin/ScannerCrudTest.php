<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\BarcodeScannerType;
use App\Entity\ScannerProgrammingBarcode;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The scanner programming catalog is edited through two linked EasyAdmin
 * CRUDs; this renders their pages so a broken form type or field cannot ship
 * unnoticed.
 */
final class ScannerCrudTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'scanner-crud-test@example.com';

    public function testCrudPagesRenderAndEditEscapesControlBytes(): void
    {
        $client = static::createClient(server: ['HTTP_HOST' => 'admin.localhost']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp($em);

        $admin = new User(self::ADMIN_EMAIL, 'unused', ['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        try {
            $client->loginUser($admin, 'main');

            $client->request('GET', '/barcode-scanner-type');
            self::assertResponseIsSuccessful();

            $client->request('GET', '/barcode-scanner-type/new');
            self::assertResponseIsSuccessful();

            $type = new BarcodeScannerType('Scanner Crud Test Type');
            $em->persist($type);
            $em->flush();

            $barcode = new ScannerProgrammingBarcode($type, 'Suffix');
            $barcode->payload = "ABC\r";
            $type->barcodes->add($barcode);
            $em->flush();

            $client->request('GET', '/scanner-programming-barcode');
            self::assertResponseIsSuccessful();

            $client->request('GET', '/scanner-programming-barcode/new', ['scannerType' => (string) $type->id]);
            self::assertResponseIsSuccessful();

            $client->request('GET', '/scanner-programming-barcode/' . $barcode->id . '/edit');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('ABC\\x0D', (string) $client->getResponse()->getContent());
        } finally {
            $this->cleanUp($em);
        }
    }

    private function cleanUp(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\BarcodeScannerType t WHERE t.name LIKE :p')
            ->setParameter('p', 'Scanner Crud Test%')
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :e')
            ->setParameter('e', self::ADMIN_EMAIL)
            ->execute();
    }
}
