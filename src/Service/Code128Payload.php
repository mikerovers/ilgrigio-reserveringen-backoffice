<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Code128Set;
use App\Exception\InvalidBarcodePayloadException;

/**
 * Validation and form escape handling for Code 128 scanner programming
 * payloads. Shared by the entity constraint and the barcode generator so a
 * stored row cannot take down the scanbalie page.
 */
final class Code128Payload
{
    public const MAX_LENGTH = 80;

    public static function assertEncodable(string $payload, Code128Set $set): void
    {
        if ($payload === '') {
            throw new InvalidBarcodePayloadException('De payload mag niet leeg zijn.');
        }

        if (strlen($payload) > self::MAX_LENGTH) {
            throw new InvalidBarcodePayloadException(sprintf(
                'De payload mag maximaal %d tekens lang zijn.',
                self::MAX_LENGTH,
            ));
        }

        if (str_contains($payload, "\x00")) {
            throw new InvalidBarcodePayloadException('De payload mag geen NUL-byte bevatten.');
        }

        match ($set) {
            Code128Set::AUTO => self::assertAuto($payload),
            Code128Set::A => self::assertSetA($payload),
            Code128Set::B => self::assertSetB($payload),
            Code128Set::C => self::assertSetC($payload),
        };
    }

    /**
     * Render a stored payload for the edit form: control bytes become \xNN and
     * a backslash becomes \\ so the value can be edited as plain text.
     */
    public static function escape(string $payload): string
    {
        $escaped = '';

        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $byte = $payload[$i];
            $code = ord($byte);

            if ($code < 32 || $code === 127) {
                $escaped .= sprintf('\\x%02X', $code);
                continue;
            }

            if ($byte === '\\') {
                $escaped .= '\\\\';
                continue;
            }

            $escaped .= $byte;
        }

        return $escaped;
    }

    /**
     * Turn the value typed in the form back into the payload stored on the
     * entity. Only \\ and \xNN are escapes; any other backslash is an error so
     * a vendor manual's literal text is never silently changed.
     */
    public static function unescape(string $value): string
    {
        $payload = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $byte = $value[$i];

            if ($byte !== '\\') {
                $payload .= $byte;
                continue;
            }

            if ($i + 1 >= $length) {
                throw new InvalidBarcodePayloadException(
                    'Een losse backslash is niet toegestaan; gebruik \\\\ voor een letterlijke backslash.',
                );
            }

            $next = $value[$i + 1];

            if ($next === '\\') {
                $payload .= '\\';
                $i++;
                continue;
            }

            if ($next === 'x' || $next === 'X') {
                $hex = substr($value, $i + 2, 2);
                if (strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                    throw new InvalidBarcodePayloadException(
                        'Gebruik \\xNN met twee hexadecimale cijfers voor een stuurteken.',
                    );
                }

                $payload .= chr((int) hexdec($hex));
                $i += 3;
                continue;
            }

            throw new InvalidBarcodePayloadException(
                'Gebruik \\\\ voor een letterlijke backslash of \\xNN voor een stuurteken.',
            );
        }

        return $payload;
    }

    private static function assertAuto(string $payload): void
    {
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            if (ord($payload[$i]) > 127) {
                throw new InvalidBarcodePayloadException(
                    'Automatische Code 128 ondersteunt alleen ASCII-tekens (0–127).',
                );
            }
        }
    }

    private static function assertSetA(string $payload): void
    {
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            if (ord($payload[$i]) > 95) {
                throw new InvalidBarcodePayloadException('Code 128 set A ondersteunt alleen ASCII-tekens 0–95.');
            }
        }
    }

    private static function assertSetB(string $payload): void
    {
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $code = ord($payload[$i]);
            if ($code < 32 || $code > 127) {
                throw new InvalidBarcodePayloadException('Code 128 set B ondersteunt alleen ASCII-tekens 32–127.');
            }
        }
    }

    private static function assertSetC(string $payload): void
    {
        if (strlen($payload) % 2 !== 0) {
            throw new InvalidBarcodePayloadException('Code 128 set C vereist een even aantal cijfers.');
        }

        if (preg_match('/^\d+$/', $payload) !== 1) {
            throw new InvalidBarcodePayloadException('Code 128 set C ondersteunt alleen cijfers.');
        }
    }
}
