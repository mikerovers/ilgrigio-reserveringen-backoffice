<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Sends the order confirmation email with the PDF download link. Carries the
 * order id (state read fresh from the database by the handler) and the signed
 * PDF download token.
 */
#[AsMessage('async')]
final class SendOrderEmailMessage
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $pdfDownloadToken,
    ) {
    }
}
