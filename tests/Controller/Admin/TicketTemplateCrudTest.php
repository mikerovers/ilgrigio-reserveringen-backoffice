<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\TicketTemplateCrudController;
use App\Entity\TicketTemplate;
use App\Entity\TicketTemplateSection;
use App\Entity\User;
use App\Form\TicketTemplateSectionType;
use App\Service\TicketContentSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class TicketTemplateCrudTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'ticket-template-crud-test@example.com';
    private const NAME = 'Ticket Template Crud Test';

    public function testEditFormShowsAnEditorForEveryPart(): void
    {
        $client = static::createClient(server: ['HTTP_HOST' => 'admin.localhost']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp($em);

        $admin = new User(self::ADMIN_EMAIL, 'unused', ['ROLE_ADMIN']);
        $em->persist($admin);
        $template = new TicketTemplate(self::NAME);
        foreach (['<div><strong>Een</strong></div>', '<div>Twee</div>'] as $i => $content) {
            $section = new TicketTemplateSection();
            $section->position = $i;
            $section->content = $content;
            $template->addSection($section);
        }
        $em->persist($template);
        $em->flush();

        try {
            $client->loginUser($admin, 'main');
            $crawler = $client->request('GET', '/ticket-template/' . $template->id . '/edit');
            self::assertResponseIsSuccessful();

            // Trix only works when EasyAdmin's text editor script is loaded on this page.
            self::assertStringContainsString('field-text-editor', (string) $client->getResponse()->getContent());
            self::assertCount(2, $crawler->filter('trix-editor'));
            self::assertCount(2, $crawler->filter('textarea.ea-text-editor-content'));
            self::assertCount(2, $crawler->filter('input[data-ticket-section-position]'));
        } finally {
            $this->cleanUp($em);
        }
    }

    public function testDefaultTemplateCannotBeDeleted(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $template = new TicketTemplate('Standaard');
        $template->isDefault = true;

        $this->expectException(AccessDeniedHttpException::class);

        $this->controller()->deleteEntity($entityManager, $template);
    }

    public function testNonDefaultTemplateCanBeDeleted(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $template = new TicketTemplate('Extra');
        $entityManager->expects($this->once())->method('remove')->with($template);
        $entityManager->expects($this->once())->method('flush');

        $this->controller()->deleteEntity($entityManager, $template);
    }

    public function testNewSectionWithoutPositionAndContentCanBeSubmitted(): void
    {
        self::bootKernel();
        $form = static::getContainer()->get('form.factory')->create(TicketTemplateSectionType::class);

        // What the browser posts for a part that was just added and left untouched.
        $form->submit(['content' => '', 'position' => '']);

        self::assertTrue($form->isSynchronized());
        $section = $form->getData();
        self::assertInstanceOf(TicketTemplateSection::class, $section);
        self::assertSame(0, $section->position);
        self::assertSame('', $section->content);
    }

    private function controller(): TicketTemplateCrudController
    {
        self::bootKernel();

        return new TicketTemplateCrudController(static::getContainer()->get(TicketContentSanitizer::class));
    }

    private function cleanUp(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\TicketTemplate t WHERE t.name = :n')
            ->setParameter('n', self::NAME)
            ->execute();
        $em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :e')
            ->setParameter('e', self::ADMIN_EMAIL)
            ->execute();
    }
}
