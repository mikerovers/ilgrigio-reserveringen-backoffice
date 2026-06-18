<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use Endroid\QrCode\QrCode;
use Psr\Log\LoggerInterface;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\ErrorCorrectionLevel;
use App\Entity\Order;
use App\Repository\OrderRepository;
use Symfony\Component\HttpFoundation\Request;

class SecurePdfStorageService
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
        private TicketNameService $ticketNameService,
        private OrderRepository $orderRepository,
        private string $tokenSecret,
        private int $tokenExpirationDays = 150
    ) {
    }

    /**
     * Generate a secure HMAC token for PDF access. The token only carries the
     * order number; the order is read fresh from the database when used.
     */
    public function generateSecureTokenForOrder(string $orderNumber): string
    {
        $payload = [
            'order_id' => $orderNumber,
            'iat' => time(),
            'exp' => time() + ($this->tokenExpirationDays * 24 * 60 * 60),
            'jti' => bin2hex(random_bytes(8)),
        ];

        return $this->encodeToken($payload);
    }

    /**
     * Retrieve the order by token and generate its PDF on demand.
     */
    public function getPdfByToken(string $token): ?string
    {
        $order = $this->orderByToken($token);
        if (!$order) {
            return null;
        }

        return $this->generateOrderPdf($order);
    }

    /**
     * Get the order by token (e.g. for filename generation).
     */
    public function getOrderByToken(string $token): ?Order
    {
        return $this->orderByToken($token);
    }

    private function orderByToken(string $token): ?Order
    {
        $payload = $this->decodeToken($token);
        if (!$payload) {
            return null;
        }

        $orderNumber = $payload['order_id'] ?? null;
        if (!$orderNumber) {
            $this->logger->error('Token payload missing order_id');
            return null;
        }

        $order = $this->orderRepository->findOneByOrderNumber((string) $orderNumber);
        if (!$order) {
            $this->logger->error('Order not found for PDF token', ['order_number' => $orderNumber]);
            return null;
        }

        return $order;
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
    public function generateDownloadUrl(?Request $request, string $token, ?string $fallbackBaseUrl = null): string
    {
        if ($request) {
            $baseUrl = $request->getSchemeAndHttpHost();
        } elseif ($fallbackBaseUrl) {
            $baseUrl = rtrim($fallbackBaseUrl, '/');
        } else {
            throw new \InvalidArgumentException('Either request or fallbackBaseUrl must be provided');
        }

        return $baseUrl . '/pdf/download/' . $token;
    }

    /**
     * Generate the order's ticket PDF from local entities.
     */
    private function generateOrderPdf(Order $order): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($options);

        // Build the view model the template expects (mirrors the old ticket-API
        // shape) directly from the order's issued tickets.
        $tickets = [];
        $eventDateTime = null;
        $eventName = null;

        foreach ($order->tickets as $ticket) {
            $eventName ??= $ticket->eventNameSnapshot;
            $eventDateTime ??= $ticket->eventDateTimeSnapshot->format('d-m-Y H:i');

            $tickets[] = [
                'ticket_code' => $ticket->ticketCode,
                'ticket_name' => $ticket->ticketName,
                'short_ticket_name' => $this->ticketNameService->generateShortTicketName($ticket->ticketName),
                'qr_code' => $this->generateTicketQrCode(['ticket_code' => $ticket->ticketCode]),
            ];
        }

        $ticketInfo = $tickets === [] ? null : [
            'event_name' => $eventName,
            'event_date_time' => $eventDateTime,
            'tickets' => $tickets,
        ];

        if ($ticketInfo === null) {
            $this->logger->error('No tickets issued for order PDF', [
                'order_number' => $order->orderNumber,
            ]);
        }

        $html = $this->twig->render('pdf/order.html.twig', [
            'order' => [
                'number' => $order->orderNumber,
                'billing' => [
                    'first_name' => $order->billing->firstName,
                    'last_name' => $order->billing->lastName,
                ],
            ],
            'ticket_info' => $ticketInfo,
        ]);

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
            $ticketCode = $ticketData['ticket_code'];
            $qrData = $ticketCode;
            $logContext = ['ticket_code' => $ticketCode];
        } else {
            $orderNumber = $ticketData['number'] ?? $ticketData['id'];
            $qrData = (string) $orderNumber;
            $logContext = ['order_number' => $qrData];
        }

        // Log the QR data for debugging
        $this->logger->info('Generating QR code with data', array_merge(['qr_data' => $qrData], $logContext));

        try {
            // Check if GD extension is loaded
            if (!extension_loaded('gd')) {
                $this->logger->error('GD extension not loaded - cannot generate QR code', $logContext);
                throw new \RuntimeException('GD extension is not available');
            }

            // Generate QR code without label
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
            $this->logger->info('QR code generated successfully (PNG)', array_merge([
                'data_size' => strlen($qrData)
            ], $logContext));

            // Return QR code as data URI (label will be handled in the template)
            return 'data:image/png;base64,' . base64_encode($result->getString());
        } catch (\Exception $e) {
            // Log the actual error and return the actual data as text fallback
            $this->logger->error('QR code generation failed', array_merge([
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_trace' => $e->getTraceAsString(),
                'qr_data' => $qrData,
                'gd_loaded' => extension_loaded('gd'),
                'imagick_loaded' => extension_loaded('imagick')
            ], $logContext));

            // Return the QR data as a simple text block if QR generation fails
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
          <rect width="200" height="200" fill="white" stroke="black" stroke-width="2"/>
          <text x="100" y="100" text-anchor="middle" font-family="Arial" ' .
                    'font-size="14" fill="black">' . htmlspecialchars($qrData) . '</text>
        </svg>'
            );
        }
    }
}
