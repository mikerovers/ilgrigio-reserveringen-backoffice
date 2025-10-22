<?php

namespace App\Controller;

use App\Service\OrderPdfService;
use App\Service\TicketApiService;
use App\Service\TicketNameService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestController extends AbstractController
{
    public function __construct(
        private OrderPdfService $orderPdfService,
        private TicketApiService $ticketApiService,
        private TicketNameService $ticketNameService
    ) {}

    #[Route('/test/order-pdf', name: 'test_order_pdf', methods: ['GET'])]
    public function testOrderPdf(): Response
    {
        // Sample WooCommerce order data for testing - Circus ticket
        $sampleOrderData = [
            'id' => 56074,
            'number' => 'fie56074',
            'status' => 'processing',
            'currency' => 'EUR',
            'date_created' => '2025-07-15T10:00:00',
            'total' => '15.00',
            'total_tax' => '0.00',
            'shipping_total' => '0.00',
            'payment_method_title' => 'iDEAL',
            'billing' => [
                'first_name' => 'Mike',
                'last_name' => 'Test',
                'email' => 'mike.test@example.com',
                'phone' => '+31612345678',
                'address_1' => 'Teststraat 123',
                'address_2' => '',
                'city' => 'Haaren',
                'postcode' => '5076 AB',
                'country' => 'NL'
            ],
            'shipping' => [
                'first_name' => 'Mike',
                'last_name' => 'Test',
                'address_1' => 'Teststraat 123',
                'address_2' => '',
                'city' => 'Haaren',
                'postcode' => '5076 AB',
                'country' => 'NL'
            ],
            'line_items' => [
                [
                    'name' => 'Bombolini - dinsdag 15 juli - ochtend (3 t/m 12 jaar)',
                    'quantity' => 1,
                    'price' => '15.00',
                    'total' => '15.00'
                ]
            ]
        ];

        try {
            $this->orderPdfService->processOrder($sampleOrderData);

            return new JsonResponse(['message' => 'Test order processed successfully']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/test/ticket-pdf', name: 'test_ticket_pdf', methods: ['GET'])]
    public function testTicketPdf(): Response
    {
        // Sample WooCommerce order data for testing - Circus ticket
        $sampleOrderData = [
            'id' => 93435,
            'number' => '93435',
            'status' => 'processing',
            'currency' => 'EUR',
            'date_created' => '2025-07-15T10:00:00',
            'total' => '36.00',
            'total_tax' => '2.97',
            'shipping_total' => '0.00',
            'payment_method_title' => 'iDEAL',
            'billing' => [
                'first_name' => 'Mike',
                'last_name' => 'Test',
                'email' => 'mike.test@example.com',
                'phone' => '+31612345678',
                'address_1' => 'Teststraat 123',
                'address_2' => '',
                'city' => 'Haaren',
                'postcode' => '5076 AB',
                'country' => 'NL'
            ],
            'shipping' => [
                'first_name' => 'Mike',
                'last_name' => 'Test',
                'address_1' => 'Teststraat 123',
                'address_2' => '',
                'city' => 'Haaren',
                'postcode' => '5076 AB',
                'country' => 'NL'
            ]
        ];

        try {
            $pdfContent = $this->generateMockTicketPdf($sampleOrderData);

            return new Response(
                $pdfContent,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="ticket-bombolini-mock.pdf"'
                ]
            );
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate PDF with mock ticket data instead of calling the real API
     */
    private function generateMockTicketPdf(array $orderData): string
    {
        // Mock ticket API response
        $mockTicketInfo = [
            "event_name" => "Bombolini - dinsdag 15 juli - ochtend",
            "event_date" => "27 November 2025 21:44",
            "tickets" => [
                "93436" => [
                    "ticket_code" => "fie56073",
                    "ticket_name" => "3 t/m 12 jaar"
                ],
                "93437" => [
                    "ticket_code" => "fie56074",
                    "ticket_name" => "3 t/m 12 jaar"
                ],
                "93438" => [
                    "ticket_code" => "fie56075",
                    "ticket_name" => "vanaf 13 jaar (incl. volwassenen)"
                ]
            ]
        ];

        // Generate individual QR codes for each ticket
        foreach ($mockTicketInfo['tickets'] as $ticketId => &$ticket) {
            if (isset($ticket['ticket_code'])) {
                // Generate short ticket name for display
                if (isset($ticket['ticket_name'])) {
                    $ticket['short_ticket_name'] = $this->ticketNameService->generateShortTicketName($ticket['ticket_name']);
                }
                // Generate QR code
                $ticket['qr_code'] = $this->generateMockQrCode($ticket);
            }
        }

        // Use reflection to access the private twig property from OrderPdfService
        $reflection = new \ReflectionClass($this->orderPdfService);
        $twigProperty = $reflection->getProperty('twig');
        $twigProperty->setAccessible(true);
        $twig = $twigProperty->getValue($this->orderPdfService);

        // Configure Dompdf (same as in OrderPdfService)
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);

        // Generate HTML content using the same template
        $html = $twig->render('pdf/order.html.twig', [
            'order' => $orderData,
            'ticket_info' => $mockTicketInfo
        ]);

        // Load HTML and generate PDF
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Generate a mock QR code using the same method as OrderPdfService
     */
    private function generateMockQrCode(array $ticketData): string
    {
        try {
            // Build QR data with ticket code and short ticket name (if available)
            $ticketCode = $ticketData['ticket_code'];
            if (isset($ticketData['ticket_name'])) {
                $shortTicketName = $this->ticketNameService->generateShortTicketName($ticketData['ticket_name']);
                $qrData = $ticketCode . '|' . $shortTicketName;
            } else {
                $qrData = $ticketCode;
            }

            // Generate QR code with the ticket data
            $qrCode = new \Endroid\QrCode\QrCode(
                data: $qrData,
                encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
                errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Low,
                size: 200,
                margin: 10,
                roundBlockSizeMode: \Endroid\QrCode\RoundBlockSizeMode::Margin,
                foregroundColor: new \Endroid\QrCode\Color\Color(0, 0, 0),
                backgroundColor: new \Endroid\QrCode\Color\Color(255, 255, 255)
            );

            $writer = new \Endroid\QrCode\Writer\PngWriter();

            // Add label with short ticket name if available
            if (isset($ticketData['ticket_name'])) {
                $shortTicketName = $this->ticketNameService->generateShortTicketName($ticketData['ticket_name']);
                $label = new \Endroid\QrCode\Label\Label(
                    text: $shortTicketName,
                    textColor: new \Endroid\QrCode\Color\Color(0, 0, 0)
                );
                $result = $writer->write($qrCode, label: $label);
            } else {
                $result = $writer->write($qrCode);
            }

            // Convert to data URI for embedding in PDF
            return 'data:image/png;base64,' . base64_encode($result->getString());
        } catch (\Exception $e) {
            // Return a simple text fallback if QR generation fails
            $ticketCode = $ticketData['ticket_code'] ?? 'NO-CODE';
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
          <rect width="200" height="200" fill="white" stroke="black" stroke-width="2"/>
          <text x="100" y="100" text-anchor="middle" font-family="Arial" ' .
                    'font-size="14" fill="black">' . htmlspecialchars($ticketCode) . '</text>
        </svg>'
            );
        }
    }

    #[Route('/test/pdf-direct', name: 'test_pdf_direct', methods: ['GET'])]
    public function testPdfDirect(): Response
    {
        // Sample order data for direct PDF testing
        $sampleOrderData = [
            'id' => 93558,
            'number' => 'fie56074',
            'status' => 'processing',
            'currency' => 'EUR',
            'date_created' => '2025-07-15T10:00:00',
            'total' => '15.00',
            'total_tax' => '0.00',
            'shipping_total' => '0.00',
            'payment_method_title' => 'iDEAL',
            'billing' => [
                'first_name' => 'Mike',
                'last_name' => 'Test',
                'email' => 'mike.test@example.com',
                'phone' => '+31612345678',
                'address_1' => 'Teststraat 123',
                'address_2' => '',
                'city' => 'Haaren',
                'postcode' => '5076 AB',
                'country' => 'NL'
            ],
            'shipping' => [
                'first_name' => 'Mike',
                'last_name' => 'Test',
                'address_1' => 'Teststraat 123',
                'address_2' => '',
                'city' => 'Haaren',
                'postcode' => '5076 AB',
                'country' => 'NL'
            ],
            'line_items' => [
                [
                    'name' => 'Bombolini - dinsdag 15 juli - ochtend (3 t/m 12 jaar)',
                    'quantity' => 1,
                    'price' => '15.00',
                    'total' => '15.00'
                ]
            ]
        ];

        try {
            $pdfContent = $this->orderPdfService->generateOrderPdf($sampleOrderData);

            return new Response(
                $pdfContent,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="qr-test.pdf"'
                ]
            );
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/test/php-info', name: 'test_php_info', methods: ['GET'])]
    public function testPhpInfo(): Response
    {
        $extensions = [
            'gd' => extension_loaded('gd'),
            'imagick' => extension_loaded('imagick'),
            'mbstring' => extension_loaded('mbstring'),
            'zlib' => extension_loaded('zlib'),
        ];

        $gdInfo = [];
        if ($extensions['gd']) {
            $gdInfo = [
                'version' => gd_info()['GD Version'] ?? 'unknown',
                'png_support' => gd_info()['PNG Support'] ?? false,
                'jpeg_support' => gd_info()['JPEG Support'] ?? false,
            ];
        }

        return new JsonResponse([
            'php_version' => PHP_VERSION,
            'extensions' => $extensions,
            'gd_info' => $gdInfo,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ]);
    }

    #[Route('/test/email-preview', name: 'test_email_preview', methods: ['GET'])]
    public function testEmailPreview(): Response
    {
        // Sample order data for email preview
        $sampleOrderData = [
            'id' => 93558,
            'number' => 'TEST12345',
            'date_created' => '2025-08-08T15:30:00',
            'total' => '15.00',
            'billing' => [
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'email' => 'test@example.com'
            ],
            'line_items' => [
                [
                    'name' => 'Test Event - Email Preview',
                    'quantity' => 1,
                    'price' => '15.00',
                    'total' => '15.00'
                ]
            ]
        ];

        // Generate a mock download token
        $downloadToken = 'preview-token-' . uniqid();

        // Generate mock download URL
        $downloadUrl = $this->generateUrl('pdf_download', ['token' => $downloadToken], true);

        // Prepare template variables (same as in SendOrderEmailMessageHandler)
        $templateVars = [
            'order_number' => $sampleOrderData['number'],
            'order_date' => new \DateTime($sampleOrderData['date_created']),
            'customer_name' => sprintf(
                '%s %s',
                $sampleOrderData['billing']['first_name'],
                $sampleOrderData['billing']['last_name']
            ),
            'customer_email' => $sampleOrderData['billing']['email'],
            'download_url' => $downloadUrl,
            'order_total' => $sampleOrderData['total'],
            'order_data' => $sampleOrderData
        ];

        // Render the email template directly
        return $this->render('email/customer_order_confirmation.html.twig', $templateVars);
    }
}
