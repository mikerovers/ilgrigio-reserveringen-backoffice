<?php

namespace App\Controller;

use App\Service\SecurePdfStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

class PdfDownloadController extends AbstractController
{
    public function __construct(
        private SecurePdfStorageService $securePdfStorageService,
        private LoggerInterface $logger
    ) {}

    #[Route('/pdf/download/{token}', name: 'pdf_download', methods: ['GET'])]
    public function downloadPdf(string $token): Response
    {
        try {
            // Validate token
            if (!$this->securePdfStorageService->isValidToken($token)) {
                $this->logger->warning('Invalid PDF download token accessed', [
                    'token' => substr($token, 0, 8) . '...' // Log only first 8 chars for security
                ]);
                throw $this->createNotFoundException('PDF not found or token invalid');
            }

            // Get order data for filename
            $orderData = $this->securePdfStorageService->getOrderDataByToken($token);
            if (!$orderData) {
                throw $this->createNotFoundException('Order data not found');
            }

            // Generate PDF content on demand
            $pdfContent = $this->securePdfStorageService->getPdfByToken($token);
            if (!$pdfContent) {
                throw $this->createNotFoundException('Could not generate PDF');
            }

            // Create filename
            $orderNumber = $orderData['number'] ?? $orderData['id'];
            $filename = 'order-confirmation-' . $orderNumber . '.pdf';

            // Create response with PDF content
            $response = new Response($pdfContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set(
                'Content-Disposition',
                ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"'
            );

            $this->logger->info('PDF downloaded successfully', [
                'order_id' => $orderData['id'],
                'token' => substr($token, 0, 8) . '...'
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('PDF download failed', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                throw $e;
            }

            throw $this->createNotFoundException('Could not process PDF download');
        }
    }
}
