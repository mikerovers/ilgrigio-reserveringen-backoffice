<?php

declare(strict_types=1);

namespace App\Controller\Scanner;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Scanner shell page. Served only on SCANNER_HOST (see config/routes/scanner.yaml).
 * Loads the "scanner" importmap entrypoint; scanner behaviour is added by Stimulus controllers.
 */
final class ScannerController extends AbstractController
{
    #[Route('/', name: 'scanner_index', methods: ['GET'], host: '%scanner_host%')]
    public function index(): Response
    {
        return $this->render('scanner/index.html.twig');
    }
}
