<?php

declare(strict_types=1);

namespace App\Enum;

/** FFBB competition kind carried by a team's Competition/Phase (spec gestion-matchs §9). */
enum CompetitionType: string
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
    case CHAMPIONSHIP = 'CHAMPIONSHIP';
    case CUP = 'CUP';
    case BRASSAGE = 'BRASSAGE';
}
