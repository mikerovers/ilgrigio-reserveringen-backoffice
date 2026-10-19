<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Payment methods the register accepts. Cash is settled immediately (the order
 * completes at once); invoice and online are not supported yet.
 */
enum RegisterPaymentMethod: string
{
    case CASH = 'cash';
}
