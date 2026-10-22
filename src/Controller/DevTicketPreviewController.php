<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TicketRepository;
use App\Service\SecurePdfStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class DevTicketPreviewController extends AbstractController
{
    public function __construct(
        private SecurePdfStorageService $securePdfStorageService,
        private TicketRepository $ticketRepository,
    ) {
    }

    public function preview(string $ticketCode): Response
    {
        $ticket = $this->ticketRepository->findOneBy(['ticketCode' => $ticketCode]);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $response = new Response($this->securePdfStorageService->generatePdfForTicket($ticket));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            ResponseHeaderBag::DISPOSITION_INLINE . '; filename="ticket-' . $ticket->ticketCode . '.pdf"',
        );

        return $response;
    }
}
