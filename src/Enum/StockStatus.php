<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Event tickets are fixed-capacity, so there is no backorder state — you can
 * never sell beyond the available stock.
 */
enum StockStatus: string
{
    case IN_STOCK = 'instock';
    case OUT_OF_STOCK = 'outofstock';
}
