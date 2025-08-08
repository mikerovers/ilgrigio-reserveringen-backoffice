<?php

namespace App\Service;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Exceptions\ApiException;
use Psr\Log\LoggerInterface;

class MollieService
{
    private MollieApiClient $mollieClient;

    public function __construct(
        private LoggerInterface $logger,
        private string $mollieApiKey
    ) {
        $this->mollieClient = new MollieApiClient();
        $this->mollieClient->setApiKey($this->mollieApiKey);
    }

    /**
     * Get payment status from Mollie
     */
    public function getPaymentStatus(string $paymentId): array
    {
        try {
            $this->logger->info('Getting payment status from Mollie', [
                'payment_id' => $paymentId
            ]);

            $payment = $this->mollieClient->payments->get($paymentId);

            $this->logger->info('Payment status retrieved successfully', [
                'payment_id' => $paymentId,
                'status' => $payment->status,
                'amount' => $payment->amount->value ?? 'unknown'
            ]);

            return [
                'success' => true,
                'status' => $payment->status,
                'amount' => [
                    'value' => $payment->amount->value,
                    'currency' => $payment->amount->currency
                ],
                'description' => $payment->description,
                'created_at' => $payment->createdAt,
                'paid_at' => $payment->paidAt,
                'method' => $payment->method,
                'details' => $payment->details
            ];
        } catch (ApiException $e) {
            $this->logger->error('Mollie API error while getting payment status', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'status_code' => $e->getCode()
            ]);

            return [
                'success' => false,
                'message' => 'Error communicating with Mollie API: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error while getting payment status from Mollie', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Unexpected error occurred'
            ];
        }
    }

    /**
     * Get human-readable status message
     */
    public function getStatusMessage(string $status): string
    {
        return match ($status) {
            'paid' => 'Betaling gelukt',
            'pending' => 'Betaling wordt verwerkt',
            'open' => 'Betaling wordt verwerkt',
            'canceled' => 'Betaling geannuleerd',
            'expired' => 'Betaling verlopen',
            'failed' => 'Betaling mislukt',
            default => 'Onbekende betalingsstatus'
        };
    }

    /**
     * Check if payment is successful
     */
    public function isPaymentSuccessful(string $status): bool
    {
        return $status === 'paid';
    }

    /**
     * Check if payment is still processing
     */
    public function isPaymentPending(string $status): bool
    {
        return in_array($status, ['pending', 'open']);
    }

    /**
     * Check if payment has failed
     */
    public function isPaymentFailed(string $status): bool
    {
        return in_array($status, ['canceled', 'expired', 'failed']);
    }
}
