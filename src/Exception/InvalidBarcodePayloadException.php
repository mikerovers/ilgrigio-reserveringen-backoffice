<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A scanner programming payload cannot be encoded as the requested Code 128
 * symbol (empty, too long, NUL byte, characters outside the code set, or a
 * malformed \xNN escape).
 */
final class InvalidBarcodePayloadException extends \InvalidArgumentException
{
}
