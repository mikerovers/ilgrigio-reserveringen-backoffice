<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Code 128 code set used to encode a scanner programming payload.
 *
 * AUTO lets the encoder switch sets; A and B are limited to their own
 * character ranges; C encodes only an even run of digits.
 */
enum Code128Set: string
{
    case AUTO = 'auto';
    case A = 'A';
    case B = 'B';
    case C = 'C';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => 'Automatisch',
            self::A => 'Set A',
            self::B => 'Set B',
            self::C => 'Set C',
        };
    }
}
