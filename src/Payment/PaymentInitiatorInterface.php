<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;

/**
 * Provider-agnostic seam for starting a payment. The concrete implementation
 * (Omnipay, planned) creates the provider-side payment for the order and
 * returns the URL the customer should be redirected to in order to pay.
 */
interface PaymentInitiatorInterface
{
    /**
     * Initiate payment for the given (PENDING) order and return the redirect URL.
     */
    public function initiate(Order $order): string;
}
