<?php

namespace App\Message;

class SendOrderEmailMessage
{
    public function __construct(
        private array $orderData,
        private string $pdfDownloadToken
    ) {
    }

    public function getOrderData(): array
    {
        return $this->orderData;
    }

    public function getPdfDownloadToken(): string
    {
        return $this->pdfDownloadToken;
    }
}
