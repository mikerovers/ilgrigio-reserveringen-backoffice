<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\SendOrderEmailMessage;
use App\Repository\OrderRepository;
use App\Service\OrderConfirmationEmailContextFactory;
use App\Service\SecurePdfStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendOrderEmailMessageHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private SecurePdfStorageService $securePdfStorageService,
        private OrderRepository $orderRepository,
        private OrderConfirmationEmailContextFactory $emailContextFactory,
        private string $fromEmail = 'noreply@example.com',
        private string $fromName = 'Il Grigio',
        private string $adminEmail = 'admin@example.com',
    ) {
    }

    public function __invoke(SendOrderEmailMessage $message): void
    {
        $order = $this->orderRepository->find($message->orderId);

        if ($order === null) {
            $this->logger->error('SendOrderEmail for unknown order', [
                'order_id' => $message->orderId,
            ]);

            return;
        }

        $downloadToken = $message->pdfDownloadToken;

        try {
            // The PDF may fail to render; when it does we still send the email
            // and rely on the (regenerating) download link instead.
            $pdfContent = $this->securePdfStorageService->getPdfByToken($downloadToken);

            if ($pdfContent === null) {
                $this->logger->warning('PDF could not be generated; sending email without attachment', [
                    'order_number' => $order->orderNumber,
                ]);
            }

            $this->sendOrderNotification($order, $downloadToken, $pdfContent);

            $this->logger->info('Order notification email sent', [
                'order_number' => $order->orderNumber,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order notification email', [
                'order_number' => $order->orderNumber,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function sendOrderNotification(Order $order, string $downloadToken, ?string $pdfContent): void
    {
        $customerEmail = $order->billing->email;

        if (!$customerEmail) {
            $this->logger->warning('Order has no billing email; skipping confirmation', [
                'order_number' => $order->orderNumber,
            ]);

            return;
        }

        $customerSubject = $this->translator->trans(
            'email.customer.subject',
            ['%order_number%' => $order->orderNumber],
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($customerEmail)
            ->subject($customerSubject)
            ->htmlTemplate('email/customer_order_confirmation.html.twig')
            ->textTemplate('email/customer_order_confirmation.txt.twig')
            ->context($this->emailContextFactory->create($order, $downloadToken));

        if ($pdfContent !== null) {
            $email->attach($pdfContent, 'tickets-' . $order->orderNumber . '.pdf', 'application/pdf');
        }

        $this->mailer->send($email);
    }
}
