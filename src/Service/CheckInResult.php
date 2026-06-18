<?php

declare(strict_types=1);

namespace App\Service;

enum CheckInResult
{
    case ADMITTED;
    case ALREADY_CHECKED_IN;
    case VOID;
    case NOT_FOUND;

    public function isAdmitted(): bool
    {
        return $this === self::ADMITTED;
    }
}
