<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Sends the one-time login code to a customer. The code is short-lived (see
 * LoginCodeService::CODE_TTL_MINUTES) so carrying it in the queued message is
 * acceptable; only its hash is persisted on the LoginCode entity.
 */
#[AsMessage('email')]
final class SendLoginCodeMessage
{
    public function __construct(
        public readonly string $email,
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {
    }
}
