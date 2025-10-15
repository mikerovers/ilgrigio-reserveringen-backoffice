<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use Endroid\QrCode\QrCode;
use Psr\Log\LoggerInterface;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\ErrorCorrectionLevel;
use Symfony\Component\HttpFoundation\Request;

class SecurePdfStorageService
{
  public function __construct(
    private Environment $twig,
    private TicketApiService $ticketApiService,
    private LoggerInterface $logger,
    private string $tokenSecret,
    private int $tokenExpirationDays = 150
  ) {}

  /**
   * Generate a secure token for PDF access using HMAC signing
   */
  public function generateSecureToken(array $orderData): string
  {
    $expirationTime = time() + ($this->tokenExpirationDays * 24 * 60 * 60); // days to seconds

    $payload = [
      'orderData' => $orderData,
      'iat' => time(), // issued at
      'exp' => $expirationTime, // expiration time
      'jti' => bin2hex(random_bytes(8)) // unique identifier
    ];

    return $this->encodeToken($payload);
  }

  /**
   * Retrieve order data by token and generate PDF on demand
   */
  public function getPdfByToken(string $token): ?string
  {
    $payload = $this->decodeToken($token);
    if (!$payload) {
      return null;
    }

    $orderData = $payload['orderData'];

    // Generate PDF on demand (not cached)
    return $this->generateOrderPdf($orderData);
  }

  /**
   * Get order data by token (for filename generation)
   */
  public function getOrderDataByToken(string $token): ?array
  {
    $payload = $this->decodeToken($token);
    if (!$payload) {
      return null;
    }

    return $payload['orderData'];
  }

  /**
   * Check if token is valid
   */
  public function isValidToken(string $token): bool
  {
    return $this->decodeToken($token) !== null;
  }

  /**
   * Generate a secure download URL
   */
  public function generateDownloadUrl(Request $request, string $token): string
  {
    $baseUrl = $request->getSchemeAndHttpHost();

    return $baseUrl . '/pdf/download/' . $token;
  }

  /**
   * Generate PDF from order data
   */
  private function generateOrderPdf(array $orderData): string
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

  /**
   * Encode payload into a secure token using HMAC
   */
  private function encodeToken(array $payload): string
  {
    $header = [
      'typ' => 'TOKEN',
      'alg' => 'HS256'
    ];

    $headerEncoded = $this->base64UrlEncode(json_encode($header));
    $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

    $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->tokenSecret, true);
    $signatureEncoded = $this->base64UrlEncode($signature);

    return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
  }

  /**
   * Decode and verify token, return payload if valid
   */
  private function decodeToken(string $token): ?array
  {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
      return null;
    }

    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

    // Verify signature
    $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->tokenSecret, true);
    $providedSignature = $this->base64UrlDecode($signatureEncoded);

    if (!hash_equals($expectedSignature, $providedSignature)) {
      return null;
    }

    // Decode payload
    $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

    if (!is_array($payload)) {
      return null;
    }

    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
      return null; // Token has expired
    }

    return $payload;
  }

  /**
   * Base64 URL encode
   */
  private function base64UrlEncode(string $data): string
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  /**
   * Base64 URL decode
   */
  private function base64UrlDecode(string $data): string
  {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
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

      $logo = new Logo(
        path: 'https://ilgrigio.nl/wp-content/uploads/2025/05/Social-Post-1080x1080_Carrousel-post_2_Part_1-scaled.jpg',
        resizeToWidth: 50,
        punchoutBackground: true
      );

      // Create generic label
      $label = new Label(
        text: 'Label',
        textColor: new Color(255, 0, 0)
      );

      $writer = new PngWriter();
      $result = $writer->write($qrCode, $logo, $label);

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
