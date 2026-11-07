<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SitePage;
use App\Repository\SitePageRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SitePageExtension extends AbstractExtension
{
    public function __construct(
        private SitePageRepository $sitePageRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('terms_page', $this->termsPage(...)),
        ];
    }

    /**
     * Terms & conditions page (pageKey "terms"), shown in the global modal on every page.
     */
    public function termsPage(): ?SitePage
    {
        return $this->sitePageRepository->findTerms();
    }
}
