<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderSource: string
{
    /** Public ticketing flow. */
    case WEB = 'web';

    /** Created through the register (POS) API. */
    case REGISTER = 'register';
}
