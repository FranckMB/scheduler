<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintFamily: string
{
    use HasValues;

    case TIME = 'TIME';
    case DAY = 'DAY';
    case FACILITY = 'FACILITY';
    case COACH_AVAILABILITY = 'COACH_AVAILABILITY';
}
