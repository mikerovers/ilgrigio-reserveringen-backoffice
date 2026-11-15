<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Reduces ticket template HTML to bold and bullet lists. Applied when an
 * admin saves a section and again at render time, because Dompdf runs with
 * remote and PHP enabled and must never see untrusted markup.
 */
final class TicketContentSanitizer
{
    public function __construct(
        #[Target('app.ticket_content')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }
}
