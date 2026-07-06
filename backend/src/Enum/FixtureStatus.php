<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Home-fixture placement lifecycle (spec gestion-matchs, workflow 2-temps):
 * UNPLACED → PLACED (venue + kickoff set) → SUBMITTED (entered in FBI, sent to
 * the league) → VALIDATED (league confirmed). Away fixtures are imposed
 * externally and default to PLACED (their kickoff is estimated).
 */
enum FixtureStatus: string
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
    case UNPLACED = 'UNPLACED';
    case PLACED = 'PLACED';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
}
