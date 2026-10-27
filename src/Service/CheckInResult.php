<?php

declare(strict_types=1);

namespace App\Service;

enum CheckInResult: string
{
    case ADMITTED = 'ADMITTED';
    case ALREADY_CHECKED_IN = 'ALREADY_CHECKED_IN';
    case VOID = 'VOID';
    case NOT_FOUND = 'NOT_FOUND';

    public function isAdmitted(): bool
    {
        return $this === self::ADMITTED;
    }
}
