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

    /**
     * FNC1-FNC4 are the encoder bytes 0xF1-0xF4, which are not valid UTF-8 and
     * so cannot be stored in a text column. They are stored as the UTF-8
     * characters U+00F1-U+00F4 and converted by toEncoding() before encoding.
     */
    private const FNC_STORED = [
        "\xF1" => "\xC3\xB1",
        "\xF2" => "\xC3\xB2",
        "\xF3" => "\xC3\xB3",
        "\xF4" => "\xC3\xB4",
    ];

    /** Short escapes accepted in the form, besides \\ and \xNN. */
    private const SHORT_ESCAPES = [
        'a' => "\x07",
        'b' => "\x08",
        't' => "\x09",
        'n' => "\x0A",
        'v' => "\x0B",
        'f' => "\x0C",
        'r' => "\x0D",
        'e' => "\x1B",
    ];

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

        $bytes = self::toEncoding($payload);

        match ($set) {
            Code128Set::AUTO => self::assertAuto($bytes),
            Code128Set::A => self::assertSetA($bytes),
            Code128Set::B => self::assertSetB($bytes),
            Code128Set::C => self::assertSetC($bytes),
        };
    }

    /**
     * The stored payload as the byte string the barcode encoder expects, with
     * FNC1-FNC4 as the single bytes 0xF1-0xF4.
     */
    public static function toEncoding(string $payload): string
    {
        return strtr($payload, array_flip(self::FNC_STORED));
    }

    private static function isFnc(string $byte): bool
    {
        $code = ord($byte);

        return $code >= 0xF1 && $code <= 0xF4;
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

            $fnc = array_search(substr($payload, $i, 2), self::FNC_STORED, true);
            if ($fnc !== false) {
                $escaped .= sprintf('\\x%02X', ord($fnc));
                $i++;
                continue;
            }

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
     * entity. Escapes are \\, \xNN and \a \b \t \n \v \f \r \e; any other backslash is an error so
     * a vendor manual's literal text is never silently changed.
     */
    public static function unescape(string $value): string
    {
        $payload = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $byte = $value[$i];

            if ($byte !== '\\') {
                if (ord($byte) > 127) {
                    throw new InvalidBarcodePayloadException(
                        'Gebruik alleen ASCII-tekens; typ de functietekens FNC1–FNC4 als \\xF1 t/m \\xF4.',
                    );
                }

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

            if (isset(self::SHORT_ESCAPES[$next])) {
                $payload .= self::SHORT_ESCAPES[$next];
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

                $code = (int) hexdec($hex);
                if ($code > 127) {
                    if ($code < 0xF1 || $code > 0xF4) {
                        throw new InvalidBarcodePayloadException(
                            'Alleen \\xF1 t/m \\xF4 (FNC1–FNC4) zijn toegestaan boven \\x7F.',
                        );
                    }

                    $payload .= self::FNC_STORED[chr($code)];
                } else {
                    $payload .= chr($code);
                }

                $i += 3;
                continue;
            }

            throw new InvalidBarcodePayloadException(
                'Gebruik \\\\ voor een letterlijke backslash, of \\xNN, \\r, \\n, \\t of \\e voor een stuurteken.',
            );
        }

        return $payload;
    }

    private static function assertAuto(string $payload): void
    {
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            if (ord($payload[$i]) > 127 && !self::isFnc($payload[$i])) {
                throw new InvalidBarcodePayloadException(
                    'Automatische Code 128 ondersteunt alleen ASCII-tekens (0–127) en FNC1–FNC4.',
                );
            }
        }
    }

    private static function assertSetA(string $payload): void
    {
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            if (ord($payload[$i]) > 95 && !self::isFnc($payload[$i])) {
                throw new InvalidBarcodePayloadException('Code 128 set A ondersteunt alleen ASCII-tekens 0–95.');
            }
        }
    }

    private static function assertSetB(string $payload): void
    {
        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $code = ord($payload[$i]);
            if (($code < 32 || $code > 127) && !self::isFnc($payload[$i])) {
                throw new InvalidBarcodePayloadException('Code 128 set B ondersteunt alleen ASCII-tekens 32–127.');
            }
        }
    }

    private static function assertSetC(string $payload): void
    {
        // Only a leading FNC1 is supported in set C.
        if ($payload !== '' && ord($payload[0]) === 0xF1) {
            $payload = substr($payload, 1);
        }

        if (strlen($payload) % 2 !== 0) {
            throw new InvalidBarcodePayloadException('Code 128 set C vereist een even aantal cijfers.');
        }

        if (preg_match('/^\d+$/', $payload) !== 1) {
            throw new InvalidBarcodePayloadException('Code 128 set C ondersteunt alleen cijfers.');
        }
    }
}
