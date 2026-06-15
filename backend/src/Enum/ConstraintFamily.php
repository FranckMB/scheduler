<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintFamily: string
{
    case TIME = 'TIME';
    case DAY = 'DAY';
    case FACILITY = 'FACILITY';
    case COACH_AVAILABILITY = 'COACH_AVAILABILITY';
    case FACILITY_CAPACITY = 'FACILITY_CAPACITY';
}
