<?php

namespace App\MessageHandler;

use App\Message\SendOrderEmailMessage;
use App\Service\SecurePdfStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

#[AsMessageHandler]
class SendOrderEmailMessageHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private SecurePdfStorageService $securePdfStorageService,
        private RequestStack $requestStack,
        private string $fromEmail = 'noreply@example.com',
        private string $adminEmail = 'admin@example.com'
    ) {
    }

    public function __invoke(SendOrderEmailMessage $message): void
    {
        $orderData = $message->getOrderData();
        $downloadToken = $message->getPdfDownloadToken();

        try {
          // Generate PDF for attachment
            $pdfContent = $this->securePdfStorageService->getPdfByToken($downloadToken);

            $this->sendOrderNotification($orderData, $downloadToken, $pdfContent);

            $this->logger->info('Order notification emails sent via messenger', [
            'order_id' => $orderData['id']
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order notification emails via messenger', [
            'order_id' => $orderData['id'] ?? 'unknown',
            'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function sendOrderNotification(array $orderData, string $downloadToken, string $pdfContent): void
    {
        $orderNumber = $orderData['number'] ?? $orderData['id'];
        $customerEmail = $orderData['billing']['email'] ?? null;

      // Generate download URL (use a default base URL since we might not have a request context)
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $downloadUrl = $this->securePdfStorageService->generateDownloadUrl($request, $downloadToken);
        } else {
          // Fallback URL - you might want to configure this as a parameter
            $downloadUrl = 'https://your-domain.com/pdf/download/' . $downloadToken;
        }

        $customerEmail = 'mikerovers97@gmail.com'; // For testing purposes, replace with $customerEmail

      // Send confirmation email to customer if email is available
        if ($customerEmail) {
          // Prepare template variables
            $templateVars = [
            'order_number' => $orderNumber,
            'order_date' => new \DateTime(),
            'customer_name' => $this->getCustomerName($orderData),
            'customer_email' => $customerEmail,
            'download_url' => $downloadUrl,
            'order_total' => $orderData['total'] ?? null,
            'order_data' => $orderData
            ];

            $customerSubject = $this->translator->trans('email.customer.subject', ['%order_number%' => $orderNumber]);

            $customerConfirmation = (new TemplatedEmail())
            ->from($this->fromEmail)
            ->to($customerEmail)
            ->subject($customerSubject)
            ->htmlTemplate('email/customer_order_confirmation.html.twig')
            ->textTemplate('email/customer_order_confirmation.txt.twig')
            ->context($templateVars)
            ->attach($pdfContent, 'order-confirmation-' . $orderNumber . '.pdf', 'application/pdf')
            ->addPart((new DataPart(new File(__DIR__ . '/../../assets/images/Logo-IlGrigioClown.png'), 'logo', 'image/png'))->asInline());

            $this->mailer->send($customerConfirmation);
        }

        $this->logger->info('Order notification emails sent', [
        'order_id' => $orderData['id'],
        'customer_email_sent' => !empty($customerEmail),
        'customer_email' => $customerEmail
        ]);
    }

    private function getCustomerName(array $orderData): ?string
    {
      // Try to get customer name from billing information
        $billing = $orderData['billing'] ?? [];

        $firstName = $billing['first_name'] ?? '';
        $lastName = $billing['last_name'] ?? '';

        if (!empty($firstName) || !empty($lastName)) {
            return trim($firstName . ' ' . $lastName);
        }

      // Fallback to shipping information if billing is not available
        $shipping = $orderData['shipping'] ?? [];
        $firstName = $shipping['first_name'] ?? '';
        $lastName = $shipping['last_name'] ?? '';

        if (!empty($firstName) || !empty($lastName)) {
            return trim($firstName . ' ' . $lastName);
        }

        return null;
    }
}
