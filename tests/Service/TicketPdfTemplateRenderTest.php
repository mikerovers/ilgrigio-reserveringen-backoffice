<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

class TicketPdfTemplateRenderTest extends KernelTestCase
{
    public function testSectionsReplaceTheBuiltInText(): void
    {
        $html = $this->render(['<div><strong>Eigen tekst</strong></div><ul><li>Punt</li></ul>']);

        $this->assertStringContainsString('<strong>Eigen tekst</strong>', $html);
        $this->assertStringContainsString('<li>Punt</li>', $html);
        $this->assertStringNotContainsString('Wij wensen je een fijne voorstelling!', $html);
        // The fixed part above the editable block is untouched.
        $this->assertStringContainsString('T-123', $html);
        $this->assertStringContainsString('Zaal open', $html);
    }

    public function testEachSectionIsItsOwnBlockSoTheDividerCanApply(): void
    {
        $html = $this->render(['<div>Een</div>', '<div>Twee</div>']);

        $this->assertSame(2, substr_count($html, 'class="ticket-section"'));
        $this->assertStringContainsString('.ticket-section + .ticket-section', $html);
    }

    public function testNoTemplateRendersNoSections(): void
    {
        $html = $this->render(null);

        $this->assertStringNotContainsString('class="ticket-section"', $html);
        $this->assertStringNotContainsString('Dit document is jouw originele toegangsbewijs.', $html);
        $this->assertStringNotContainsString('Wij wensen je een fijne voorstelling!', $html);
        // The fixed header is still there.
        $this->assertStringContainsString('T-123', $html);
    }

    /**
     * @param list<string>|null $sections
     */
    private function render(?array $sections): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->render('pdf/order.html.twig', [
            'order' => ['number' => 'ORD-1', 'billing' => ['first_name' => 'A', 'last_name' => 'B']],
            'ticket_info' => [
                'event_name' => 'Show',
                'event_date' => '1 januari',
                'event_description' => null,
                'event_start_time' => '14:00',
                'event_doors_open' => '13:00',
                'sections' => $sections,
                'tickets' => [[
                    'ticket_code' => 'T-123',
                    'ticket_name' => 'Kind',
                    'short_ticket_name' => 'KIND',
                    'qr_code' => 'data:image/png;base64,AAAA',
                ]],
            ],
        ]);
    }
}
