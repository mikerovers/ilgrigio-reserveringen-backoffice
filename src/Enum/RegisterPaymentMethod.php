<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Payment methods the register can settle immediately, without Mollie.
 */
enum RegisterPaymentMethod: string
{
    case CASH = 'cash';
    case PIN = 'pin';
}
