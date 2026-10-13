<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;
use App\Entity\Payment;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Omnipay\Mollie\Gateway;
use Omnipay\Mollie\Message\Response\PurchaseResponse;
use Psr\Log\LoggerInterface;

final class OmnipayMolliePaymentInitiator implements PaymentInitiatorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $mollieApiKey,
        private string $returnUrl,
        private string $webhookUrl,
    ) {
    }

    public function initiate(Order $order): string
    {
        $gateway = new Gateway();
        $gateway->setApiKey($this->mollieApiKey);

        $amountEuros = number_format((int) $order->totalGross->getAmount() / 100, 2, '.', '');

        /** @var PurchaseResponse $response */
        $response = $gateway->purchase([
            'amount'        => $amountEuros,
            'currency'      => 'EUR',
            'description'   => 'Bestelling ' . $order->orderNumber,
            'returnUrl'     => $this->returnUrl,
            'notifyUrl'     => $this->webhookUrl,
            'transactionId' => $order->orderNumber,
        ])->send();

        if (!$response->isRedirect()) {
            throw new \RuntimeException('Mollie did not return a redirect: ' . $response->getMessage());
        }

        $payment = new Payment($order, $order->totalGross);
        $payment->molliePaymentId = $response->getTransactionReference();
        $payment->status = PaymentStatus::OPEN;
        $payment->description = 'Bestelling ' . $order->orderNumber;

        $order->payment = $payment;

        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->logger->info('Mollie payment initiated', [
            'order_number'      => $order->orderNumber,
            'mollie_payment_id' => $payment->molliePaymentId,
        ]);

        return (string) $response->getRedirectUrl();
    }
}
