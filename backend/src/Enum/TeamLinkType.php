<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The nature of a declared team link (cadrage P1-4 §6). Cross-module by
 * design: matches consume it first (PR C/D), the training solver will later
 * (vision « passerelles ») — hence the neutral naming.
 */
enum TeamLinkType: string
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
    /**
     * The two teams share players (« double projets » — no player entity
     * exists, the manager DECLARES the bridge): their matches should never
     * overlap. Objective rule → radar finding + PR D SOFT constraint.
     */
    case NOT_SIMULTANEOUS = 'NOT_SIMULTANEOUS';

    /**
     * The club WANTS the two teams chained (« SF1 et PNM : l'un après
     * l'autre ») — implies NOT_SIMULTANEOUS. A preference, not a rule: no
     * radar finding (PR D consumes it as a SOFT objective).
     */
    case BACK_TO_BACK = 'BACK_TO_BACK';
}
