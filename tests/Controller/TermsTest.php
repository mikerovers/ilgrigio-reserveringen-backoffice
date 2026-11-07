<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SitePage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TermsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var array{title: string, description: string}|null Original row values, null when no row existed. */
    private ?array $original = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $page = $this->findTermsRow();
        if (null !== $page) {
            $this->original = ['title' => $page->heroTitle, 'description' => $page->heroDescription];
        }

        $page ??= $this->createTermsRow();
        $page->heroTitle = 'Testvoorwaarden';
        $page->heroDescription = "Eerste alinea <script>alert(1)</script>\r\n\r\nTweede alinea";
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $page = $this->findTermsRow();
        if (null === $this->original) {
            if (null !== $page) {
                $this->em->remove($page);
            }
        } else {
            $page ??= $this->createTermsRow();
            $page->heroTitle = $this->original['title'];
            $page->heroDescription = $this->original['description'];
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testTermsPageRendersEditedTitleAndEscapedParagraphs(): void
    {
        $this->client->request('GET', '/algemene-voorwaarden/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Testvoorwaarden');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<p>Tweede alinea</p>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testModalOnStorefrontShowsEditedTerms(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Testvoorwaarden', $this->client->getResponse()->getContent());
        self::assertStringContainsString('<p>Tweede alinea</p>', $this->client->getResponse()->getContent());
    }

    public function testTermsPageIsNotFoundWhenRowIsMissing(): void
    {
        $page = $this->findTermsRow();
        self::assertNotNull($page);
        $this->em->remove($page);
        $this->em->flush();

        $this->client->request('GET', '/algemene-voorwaarden/');

        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-modal-open="terms"', (string) $this->client->getResponse()->getContent());
    }

    private function findTermsRow(): ?SitePage
    {
        return $this->em->getRepository(SitePage::class)->findTerms();
    }

    private function createTermsRow(): SitePage
    {
        $page = new SitePage('terms', 'Algemene Voorwaarden', '', '');
        $this->em->persist($page);

        return $page;
    }
}
