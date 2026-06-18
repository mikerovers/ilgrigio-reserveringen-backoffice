<?php

declare(strict_types=1);

namespace App\Enum;

enum TicketStatus: string
{
    /** Minted, not yet scanned at the door. */
    case ISSUED = 'issued';

    /** Admitted at the door. */
    case CHECKED_IN = 'checked_in';

    /** Invalidated because its order was cancelled or refunded. */
    case VOID = 'void';
}
