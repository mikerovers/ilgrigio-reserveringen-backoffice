<?php

namespace App\Service;

use App\Message\SendOrderEmailMessage;
use App\Service\SecurePdfStorageService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

class OrderPdfService
{
  public function __construct(
    private MessageBusInterface $messageBus,
    private LoggerInterface $logger,
    private Environment $twig,
    private SecurePdfStorageService $securePdfStorageService,
    private TicketApiService $ticketApiService
  ) {}

  public function processOrder(array $orderData): void
  {
    try {
      // Generate secure token for PDF download
      $downloadToken = $this->securePdfStorageService->generateSecureToken($orderData);

      // Dispatch message to send email with PDF download link via messenger
      $this->messageBus->dispatch(new SendOrderEmailMessage($orderData, $downloadToken));

      $this->logger->info('Order processed successfully', [
        'order_id' => $orderData['id']
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to process order', [
        'order_id' => $orderData['id'] ?? 'unknown',
        'error' => $e->getMessage()
      ]);
      throw $e;
    }
  }

  public function generateOrderPdf(array $orderData): string
  {
    // Configure Dompdf
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', true);
    $options->set('isFontSubsettingEnabled', true);

    $dompdf = new Dompdf($options);

    // Fetch ticket information from the API
    $orderId = $orderData['id'] ?? $orderData['number'];
    $ticketInfo = $this->ticketApiService->getTicketInformation((string) $orderId);

    if ($ticketInfo && $this->ticketApiService->validateTicketResponse($ticketInfo)) {
      $this->logger->info('Using ticket information from API', [
        'order_id' => $orderId,
        'event_name' => $ticketInfo['event_name'],
        'ticket_count' => count($ticketInfo['tickets'])
      ]);

      // Generate individual QR codes for each ticket
      foreach ($ticketInfo['tickets'] as $ticketId => &$ticket) {
        if (isset($ticket['ticket_code'])) {
          $ticket['qr_code'] = $this->generateTicketQrCode(['ticket_code' => $ticket['ticket_code']]);
        }
      }
    } else {
      $this->logger->error('Failed to fetch ticket information from API', [
        'order_id' => $orderId
      ]);
      // Don't fallback, let the template handle the error
      $ticketInfo = null;
    }

    // Generate HTML content using Twig template (translations handled by Twig)
    $html = $this->twig->render('pdf/order.html.twig', [
      'order' => $orderData,
      'ticket_info' => $ticketInfo
    ]);

    // Load HTML and generate PDF
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
  }

  private function generateTicketQrCode(array $ticketData): string
  {
    // Use ticket_code if available, otherwise fall back to order number
    if (isset($ticketData['ticket_code'])) {
      $qrData = $ticketData['ticket_code'];
      $logContext = ['ticket_code' => $qrData];
    } else {
      $orderNumber = $ticketData['number'] ?? $ticketData['id'];
      $qrData = (string) $orderNumber;
      $logContext = ['order_number' => $qrData];
    }

    // Log the QR data for debugging
    $this->logger->info('Generating QR code with data', array_merge(['qr_data' => $qrData], $logContext));

    try {
      // Generate QR code with proper constructor for version 6.x
      $qrCode = new QrCode(
        data: $qrData,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::Low,
        size: 200,
        margin: 10,
        roundBlockSizeMode: RoundBlockSizeMode::Margin,
        foregroundColor: new Color(0, 0, 0),
        backgroundColor: new Color(255, 255, 255)
      );

      $writer = new PngWriter();
      $result = $writer->write($qrCode);

      // Log success
      $this->logger->info('QR code generated successfully', array_merge([
        'data_size' => strlen($qrData)
      ], $logContext));

      // Convert to data URI for embedding in PDF
      return 'data:image/png;base64,' . base64_encode($result->getString());
    } catch (\Exception $e) {
      // Log the actual error and return the actual data as text fallback
      $this->logger->error('QR code generation failed', array_merge([
        'error' => $e->getMessage(),
        'qr_data' => $qrData
      ], $logContext));

      // Return the QR data as a simple text block if QR generation fails
      return 'data:image/svg+xml;base64,' . base64_encode(
        '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
          <rect width="200" height="200" fill="white" stroke="black" stroke-width="2"/>
          <text x="100" y="100" text-anchor="middle" font-family="Arial" font-size="14" fill="black">' . htmlspecialchars($qrData) . '</text>
        </svg>'
      );
    }
  }
}
