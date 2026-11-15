<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\TicketTemplate;
use App\Repository\TicketTemplateRepository;

/**
 * Finds the template for an event (its own, else the default) and returns
 * its sections as sanitized HTML strings, in order.
 */
final class TicketTemplateResolver
{
    public function __construct(
        private readonly TicketTemplateRepository $repository,
        private readonly TicketContentSanitizer $sanitizer,
    ) {
    }

    public function forEvent(?Event $event): ?TicketTemplate
    {
        return $event?->ticketTemplate ?? $this->repository->findDefault();
    }

    /**
     * @return list<string>|null null when no template exists, so the caller
     *                           keeps the built-in text
     */
    public function sectionsForEvent(?Event $event): ?array
    {
        $template = $this->forEvent($event);
        if ($template === null) {
            return null;
        }

        $sections = [];
        foreach ($template->sections as $section) {
            $html = $this->sanitizer->sanitize($section->content);
            if ($html !== '' && trim(strip_tags($html)) !== '') {
                $sections[] = $html;
            }
        }

        return $sections;
    }
}
