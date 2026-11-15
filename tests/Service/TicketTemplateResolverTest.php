<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Entity\TicketTemplate;
use App\Entity\TicketTemplateSection;
use App\Repository\TicketTemplateRepository;
use App\Service\TicketContentSanitizer;
use App\Service\TicketTemplateResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TicketTemplateResolverTest extends KernelTestCase
{
    private TicketContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sanitizer = static::getContainer()->get(TicketContentSanitizer::class);
    }

    public function testSanitizerKeepsBoldAndListsAndStripsEverythingElse(): void
    {
        $html = $this->sanitizer->sanitize(
            '<div onclick="x()"><strong>Bold</strong> <a href="https://evil.test">link</a>'
            . '<script>alert(1)</script><img src="https://evil.test/a.png"></div>'
            . '<ul><li>One</li></ul>'
        );

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<ul><li>One</li></ul>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('script', $html);
    }

    public function testEventTemplateWinsOverDefault(): void
    {
        $repository = $this->createMock(TicketTemplateRepository::class);
        $repository->method('findDefault')->willReturn($this->template('Default text'));

        $event = new Event('Show', new \DateTimeImmutable());
        $event->ticketTemplate = $this->template('<div>Own text</div><script>x</script>');

        $sections = (new TicketTemplateResolver($repository, $this->sanitizer))->sectionsForEvent($event);

        $this->assertSame(['<div>Own text</div>'], $sections);
    }

    public function testFallsBackToDefaultTemplate(): void
    {
        $repository = $this->createMock(TicketTemplateRepository::class);
        $repository->method('findDefault')->willReturn($this->template('<div>Default text</div>'));

        $sections = (new TicketTemplateResolver($repository, $this->sanitizer))
            ->sectionsForEvent(new Event('Show', new \DateTimeImmutable()));

        $this->assertSame(['<div>Default text</div>'], $sections);
    }

    public function testReturnsNullWhenNoTemplateExists(): void
    {
        $repository = $this->createMock(TicketTemplateRepository::class);
        $repository->method('findDefault')->willReturn(null);

        $this->assertNull(
            (new TicketTemplateResolver($repository, $this->sanitizer))->sectionsForEvent(null)
        );
    }

    public function testEmptySectionsAreSkippedAndOrderIsKept(): void
    {
        $repository = $this->createMock(TicketTemplateRepository::class);
        $template = new TicketTemplate('T');
        foreach (['<div>First</div>', '<div> </div>', '<div>Third</div>'] as $i => $content) {
            $section = new TicketTemplateSection();
            $section->position = $i;
            $section->content = $content;
            $template->addSection($section);
        }
        $repository->method('findDefault')->willReturn($template);

        $sections = (new TicketTemplateResolver($repository, $this->sanitizer))->sectionsForEvent(null);

        $this->assertSame(['<div>First</div>', '<div>Third</div>'], $sections);
    }

    private function template(string $content): TicketTemplate
    {
        $template = new TicketTemplate('T');
        $section = new TicketTemplateSection();
        $section->content = $content;
        $template->addSection($section);

        return $template;
    }
}
