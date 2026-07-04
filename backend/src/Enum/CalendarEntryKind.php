<?php

declare(strict_types=1);

namespace App\Enum;

enum CalendarEntryKind: string
{
    case EVENT = 'event';
    case PERIOD = 'period';
}
