<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintScope: string
{
    case CLUB = 'CLUB';
    case TEAM = 'TEAM';
    case COACH = 'COACH';
    case FACILITY = 'FACILITY';
}
