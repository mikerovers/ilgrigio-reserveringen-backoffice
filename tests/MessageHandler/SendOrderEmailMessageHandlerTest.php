<?php

namespace App\Tests\MessageHandler;

use App\Message\SendOrderEmailMessage;
use App\MessageHandler\SendOrderEmailMessageHandler;
use App\Service\SecurePdfStorageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

class SendOrderEmailMessageHandlerTest extends TestCase
{
    private MailerInterface $mailer;
    private LoggerInterface $logger;
    private TranslatorInterface $translator;
    private SecurePdfStorageService $securePdfStorageService;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->securePdfStorageService = $this->createMock(SecurePdfStorageService::class);
        $this->requestStack = $this->createMock(RequestStack::class);
    }

    public function testEmailUsesFromNameFromEnvironment(): void
    {
        $fromEmail = 'noreply@ilgrigio.nl';
        $fromName = 'Il Grigio Reserveringen';
        $appBaseUrl = 'https://reserveringen.ilgrigio.nl';
        $adminEmail = 'admin@ilgrigio.nl';

        $handler = new SendOrderEmailMessageHandler(
            $this->mailer,
            $this->logger,
            $this->translator,
            $this->securePdfStorageService,
            $this->requestStack,
            $appBaseUrl,
            $fromEmail,
            $fromName,
            $adminEmail
        );

        $orderData = [
            'id' => 12345,
            'number' => 'ORD-12345',
            'billing' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com'
            ],
            'total' => '100.00'
        ];

        $downloadToken = 'test-token-123';
        $pdfContent = 'fake-pdf-content';

        $this->securePdfStorageService
            ->expects($this->once())
            ->method('getPdfByToken')
            ->with($downloadToken)
            ->willReturn($pdfContent);

        $this->securePdfStorageService
            ->expects($this->once())
            ->method('generateDownloadUrl')
            ->willReturn('https://reserveringen.ilgrigio.nl/pdf/download?token=' . $downloadToken);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->with('email.customer.subject', ['%order_number%' => 'ORD-12345'])
            ->willReturn('Your order ORD-12345');

        // Capture the email that is sent
        $capturedEmail = null;
        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$capturedEmail) {
                $capturedEmail = $email;
            });

        $message = new SendOrderEmailMessage($orderData, $downloadToken);
        $handler($message);

        // Verify the email was captured
        $this->assertNotNull($capturedEmail, 'Email should have been sent');

        // Verify the "from" address includes both email and name
        $from = $capturedEmail->getFrom();
        $this->assertCount(1, $from, 'Should have exactly one from address');

        $fromAddress = $from[0];
        $this->assertInstanceOf(Address::class, $fromAddress);
        $this->assertEquals($fromEmail, $fromAddress->getAddress(), 'From email should match');
        $this->assertEquals($fromName, $fromAddress->getName(), 'From name should match');
    }

    public function testEmailUsesDefaultFromNameWhenNotProvided(): void
    {
        $fromEmail = 'noreply@example.com';
        $defaultFromName = 'Il Grigio';
        $appBaseUrl = 'https://example.com';
        $adminEmail = 'admin@example.com';

        $handler = new SendOrderEmailMessageHandler(
            $this->mailer,
            $this->logger,
            $this->translator,
            $this->securePdfStorageService,
            $this->requestStack,
            $appBaseUrl,
            $fromEmail,
            $defaultFromName,
            $adminEmail
        );

        $orderData = [
            'id' => 54321,
            'number' => 'ORD-54321',
            'billing' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane@example.com'
            ],
            'total' => '75.00'
        ];

        $downloadToken = 'test-token-456';
        $pdfContent = 'fake-pdf-content-2';

        $this->securePdfStorageService
            ->expects($this->once())
            ->method('getPdfByToken')
            ->with($downloadToken)
            ->willReturn($pdfContent);

        $this->securePdfStorageService
            ->expects($this->once())
            ->method('generateDownloadUrl')
            ->willReturn('https://example.com/pdf/download?token=' . $downloadToken);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->with('email.customer.subject', ['%order_number%' => 'ORD-54321'])
            ->willReturn('Your order ORD-54321');

        $capturedEmail = null;
        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$capturedEmail) {
                $capturedEmail = $email;
            });

        $message = new SendOrderEmailMessage($orderData, $downloadToken);
        $handler($message);

        $this->assertNotNull($capturedEmail);

        $from = $capturedEmail->getFrom();
        $fromAddress = $from[0];
        $this->assertEquals($fromEmail, $fromAddress->getAddress());
        $this->assertEquals($defaultFromName, $fromAddress->getName());
    }

    public function testEmailIsSentWithoutAttachmentWhenPdfGenerationFails(): void
    {
        $handler = new SendOrderEmailMessageHandler(
            $this->mailer,
            $this->logger,
            $this->translator,
            $this->securePdfStorageService,
            $this->requestStack,
            'https://example.com',
            'noreply@example.com',
            'Il Grigio',
            'admin@example.com'
        );

        $orderData = [
            'id' => 99999,
            'number' => 'ORD-99999',
            'billing' => [
                'first_name' => 'Sam',
                'last_name' => 'Jones',
                'email' => 'sam@example.com'
            ],
            'total' => '20.00'
        ];

        $downloadToken = 'test-token-no-pdf';

        // PDF generation fails (e.g. order temporarily unavailable): returns null.
        $this->securePdfStorageService
            ->expects($this->once())
            ->method('getPdfByToken')
            ->with($downloadToken)
            ->willReturn(null);

        $this->securePdfStorageService
            ->expects($this->once())
            ->method('generateDownloadUrl')
            ->willReturn('https://example.com/pdf/download?token=' . $downloadToken);

        $this->translator
            ->expects($this->once())
            ->method('trans')
            ->willReturn('Your order ORD-99999');

        // The email should still be sent, but without any attachment.
        $capturedEmail = null;
        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Email $email) use (&$capturedEmail) {
                $capturedEmail = $email;
            });

        $message = new SendOrderEmailMessage($orderData, $downloadToken);
        $handler($message);

        $this->assertNotNull($capturedEmail, 'Email should still be sent without the PDF');
        $this->assertCount(0, $capturedEmail->getAttachments(), 'Email should have no attachments');
    }
}
