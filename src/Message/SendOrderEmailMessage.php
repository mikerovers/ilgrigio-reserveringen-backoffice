<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
class SendOrderEmailMessage
{
    public function __construct(
        private array $orderData,
        private string $pdfDownloadToken
    ) {}

    public function getOrderData(): array
    {
        return $this->orderData;
    }

    public function getPdfDownloadToken(): string
    {
        return $this->pdfDownloadToken;
    }
}
