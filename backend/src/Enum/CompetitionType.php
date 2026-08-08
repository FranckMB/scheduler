<?php

declare(strict_types=1);

namespace App\Enum;

/** FFBB competition kind carried by a team's Competition/Phase (spec gestion-matchs §9). */
enum CompetitionType: string
{
    use HasValues;

    case CHAMPIONSHIP = 'CHAMPIONSHIP';
    case CUP = 'CUP';
    case BRASSAGE = 'BRASSAGE';
}
