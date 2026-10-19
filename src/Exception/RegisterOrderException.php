<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A register order request that cannot be fulfilled, carrying the HTTP status
 * the API should answer with (404 unknown ticket type, 422 invalid input).
 */
final class RegisterOrderException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}
