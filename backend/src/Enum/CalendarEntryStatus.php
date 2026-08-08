<?php

declare(strict_types=1);

namespace App\Enum;

enum CalendarEntryStatus: string
{
    use HasValues;

    case PROPOSED = 'proposed';
    case ACTIVE = 'active';
    case IGNORED = 'ignored';
}
