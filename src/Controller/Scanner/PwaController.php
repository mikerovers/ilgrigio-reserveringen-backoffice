<?php

declare(strict_types=1);

namespace App\Controller\Scanner;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Makes the scanner installable as an app. Both routes are public (see
 * security.yaml): the browser fetches them before / without a session.
 */
final class PwaController extends AbstractController
{
    #[Route('/manifest.webmanifest', name: 'scanner_manifest', host: '%scanner_host%', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        $response = new JsonResponse([
            'name' => 'Scanbalie',
            'short_name' => 'Scanbalie',
            'description' => 'Ticketcontrole voor Jeugdcircus Il Grigio',
            'lang' => 'nl',
            'id' => '/',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // An installed window on Windows gets the native-feeling window
            // controls overlay (see the display-mode CSS block in the scanner
            // base template); everywhere else it falls back to standalone.
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'any',
            'theme_color' => '#c62828',
            'background_color' => '#fffaf2',
            'icons' => [
                ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // Jump tasks in the Windows taskbar / long-press menu of the icon.
            'shortcuts' => [
                [
                    'name' => 'Scanstation',
                    'short_name' => 'Scan',
                    'url' => '/',
                    'description' => 'Kaarten scannen',
                ],
                [
                    'name' => 'Alle scans',
                    'short_name' => 'Scans',
                    'url' => '/scans',
                    'description' => 'Logboek van deze balie',
                ],
                [
                    'name' => 'Orders',
                    'short_name' => 'Orders',
                    'url' => '/orders',
                    'description' => 'Orders zoeken en inchecken',
                ],
            ],
        ]);
        $response->headers->set('Content-Type', 'application/manifest+json');
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    /**
     * Served from the root so its scope covers the whole app. Pages, API calls
     * and the Mercure stream are always fetched live; the worker only caches the
     * fingerprinted app shell (asset-mapper output and PWA icons) and shows an
     * offline notice when a page navigation cannot reach the server.
     */
    #[Route('/sw.js', name: 'scanner_service_worker', host: '%scanner_host%', methods: ['GET'])]
    public function serviceWorker(): Response
    {
        $response = $this->render('scanner/sw.js.twig');
        $response->headers->set('Content-Type', 'text/javascript; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }
}
