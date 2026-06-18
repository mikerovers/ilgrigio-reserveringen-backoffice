<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generates unguessable, URL/QR-safe ticket codes.
 *
 * Format: "TKT-" followed by Crockford-style base32 of 15 random bytes
 * (120 bits of entropy → 24 base32 characters), using an alphabet with no
 * ambiguous characters (no I, L, O, U). Collisions are effectively impossible;
 * the caller still guards with the unique constraint on ticket_code.
 */
final class TicketCodeGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const PREFIX = 'TKT-';
    private const RANDOM_BYTES = 15;

    public function generate(): string
    {
        return self::PREFIX . $this->base32(random_bytes(self::RANDOM_BYTES));
    }

    private function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }
}
