<?php

declare(strict_types=1);

namespace App\Enum;

enum CalendarEntryPeriodType: string
{
    case CLOSURE = 'closure';
    case HOLIDAY = 'holiday';
    case CUTOFF = 'cutoff';
    case MUTUALISATION = 'mutualisation';
    case CUSTOM = 'custom';
}
