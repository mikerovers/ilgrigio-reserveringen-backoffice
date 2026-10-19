<?php

declare(strict_types=1);

namespace App\DTO;

final class SaleReportResponse
{
    public function __construct(
        public int $year,
        public int $ticketsSold,
    ) {
    }
}
