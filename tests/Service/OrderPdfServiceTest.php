<?php

namespace App\Tests\Service;

use App\Service\OrderPdfService;
use App\Service\SecurePdfStorageService;
use App\Service\TicketApiService;
use App\Service\TicketNameService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

class OrderPdfServiceTest extends TestCase
{
    private MockObject|TicketApiService $ticketApiService;
    private MockObject|Environment $twig;
    private OrderPdfService $service;

    private array $minimalOrderData = [
        'id' => '1001',
        'number' => '1001',
        'billing' => ['first_name' => 'Jan', 'last_name' => 'Janssen'],
        'meta_data' => [],
    ];

    private array $minimalTicket = [
        'ticket_code' => 'abc123',
        'ticket_name' => 'Volwassene',
    ];

    protected function setUp(): void
    {
        $this->ticketApiService = $this->createMock(TicketApiService::class);
        $this->twig = $this->createMock(Environment::class);

        $this->service = new OrderPdfService(
            messageBus: $this->createMock(MessageBusInterface::class),
            logger: $this->createMock(LoggerInterface::class),
            twig: $this->twig,
            securePdfStorageService: $this->createMock(SecurePdfStorageService::class),
            ticketApiService: $this->ticketApiService,
            ticketNameService: $this->createMock(TicketNameService::class),
        );
    }

    public function testEventDateTimeIsPassedToTemplate(): void
    {
        $apiResponse = [
            'event_name' => 'Bombolini',
            'event_date_time' => '20:00',
            'tickets' => ['1' => $this->minimalTicket],
        ];

        $this->ticketApiService->method('getTicketInformation')->willReturn($apiResponse);
        $this->ticketApiService->method('validateTicketResponse')->willReturn(true);

        $capturedVars = null;
        $this->twig->method('render')->willReturnCallback(
            function (string $template, array $vars) use (&$capturedVars): string {
                $capturedVars = $vars;
                return '';
            }
        );

        $this->service->generateOrderPdf($this->minimalOrderData);

        $this->assertSame('20:00', $capturedVars['ticket_info']['event_date_time']);
    }

    public function testMissingEventDateTimeResultsInAbsentField(): void
    {
        $apiResponse = [
            'event_name' => 'Bombolini',
            'tickets' => ['1' => $this->minimalTicket],
        ];

        $this->ticketApiService->method('getTicketInformation')->willReturn($apiResponse);
        $this->ticketApiService->method('validateTicketResponse')->willReturn(true);

        $capturedVars = null;
        $this->twig->method('render')->willReturnCallback(
            function (string $template, array $vars) use (&$capturedVars): string {
                $capturedVars = $vars;
                return '';
            }
        );

        $this->service->generateOrderPdf($this->minimalOrderData);

        $this->assertArrayNotHasKey('event_date_time', $capturedVars['ticket_info']);
    }
}
