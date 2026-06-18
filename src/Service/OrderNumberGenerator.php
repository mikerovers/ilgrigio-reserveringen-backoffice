<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates a human-facing order number.
 *
 * Format: "IG-{YYYYMMDD}-{6 random base32 chars}", e.g. "IG-20260618-7Q2WXB".
 * The random suffix keeps numbers unguessable and avoids a central sequence;
 * the caller guards with the unique constraint on order_number.
 */
final class OrderNumberGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const PREFIX = 'IG';
    private const SUFFIX_LENGTH = 6;

    public function generate(\DateTimeImmutable $now): string
    {
        $suffix = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::SUFFIX_LENGTH; $i++) {
            $suffix .= self::ALPHABET[random_int(0, $max)];
        }

        return sprintf('%s-%s-%s', self::PREFIX, $now->format('Ymd'), $suffix);
    }
}
