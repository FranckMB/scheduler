<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who placed the fixture (P1-4 PR D). The marker that makes the RE-SOLVE
 * possible: a MANUAL placement (and anything SUBMITTED/VALIDATED) is an
 * untouchable anchor; a SOLVER placement may be re-arranged by the next solve
 * (with a stability bonus). Null on the column = never placed / legacy rows —
 * treated as MANUAL when placed (never move what we cannot attribute).
 */
enum FixturePlacementSource: string
{
    case MANUAL = 'MANUAL';
    case SOLVER = 'SOLVER';
}
