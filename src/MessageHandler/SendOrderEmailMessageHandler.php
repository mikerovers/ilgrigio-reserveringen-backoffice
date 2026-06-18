<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\SendOrderEmailMessage;
use App\Repository\OrderRepository;
use App\Service\MoneyService;
use App\Service\SecurePdfStorageService;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
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
        private MoneyService $moneyService,
        private RequestStack $requestStack,
        private string $appBaseUrl,
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

        $request = $this->requestStack->getCurrentRequest();
        $downloadUrl = $this->securePdfStorageService->generateDownloadUrl(
            $request,
            $downloadToken,
            $this->appBaseUrl,
        );

        $templateVars = [
            'order_number' => $order->orderNumber,
            'order_date' => $order->createdAt ?? new \DateTimeImmutable(),
            'customer_name' => trim($order->billing->firstName . ' ' . $order->billing->lastName),
            'customer_email' => $customerEmail,
            'download_url' => $downloadUrl,
            'order_total' => $this->moneyService->format($order->totalGross),
            'total_tax' => $this->moneyService->format($order->tax),
            'order_data' => $this->buildOrderView($order),
        ];

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
            ->context($templateVars);

        if ($pdfContent !== null) {
            $email->attach($pdfContent, 'tickets-' . $order->orderNumber . '.pdf', 'application/pdf');
        }

        $this->mailer->send($email);
    }

    /**
     * View model matching the keys the email templates read off `order_data`.
     */
    private function buildOrderView(Order $order): array
    {
        $lineItems = [];
        foreach ($order->lineItems as $lineItem) {
            $lineItems[] = [
                'name' => $lineItem->name,
                'quantity' => $lineItem->quantity,
                'total' => $this->moneyService->format($lineItem->lineGross),
            ];
        }

        $couponLines = [];
        if ($order->couponCode !== null && !$order->discountGross->isZero()) {
            $couponLines[] = [
                'code' => $order->couponCode,
                'discount' => $this->moneyService->format($order->discountGross),
            ];
        }

        return [
            'line_items' => $lineItems,
            'coupon_lines' => $couponLines,
            'payment_method_title' => $order->payment?->method ?? 'iDEAL',
        ];
    }
}
