<?php

declare(strict_types=1);

namespace App\Enum;

enum ImplicitConstraint: string
{
    case VENUE_AT_MOST_ONE = 'VENUE_AT_MOST_ONE';
    case COACH_NO_OVERLAP = 'COACH_NO_OVERLAP';
    case COACH_PLAYER_NO_OVERLAP = 'COACH_PLAYER_NO_OVERLAP';
    case TEAM_NO_OVERLAP = 'TEAM_NO_OVERLAP';
    case MIN_SESSIONS = 'MIN_SESSIONS';
}
