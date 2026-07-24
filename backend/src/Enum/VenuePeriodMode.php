<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Comment un gymnase se comporte SUR UNE PÉRIODE (réglage sparse ancré au plan).
 *
 * Le 3e mode, INHERIT, est le DÉFAUT et n'est JAMAIS stocké : l'absence de ligne
 * `venue_period_override` signifie « ce gymnase garde ses créneaux de saison ».
 * Le représenter en base créerait deux écritures pour un même sens (pas de ligne
 * vs ligne INHERIT) — pour revenir au défaut on SUPPRIME l'override.
 *
 * DISABLED = le gymnase ne sert pas du tout cette période.
 * BLANK    = on repart d'une grille vierge (les créneaux de saison sont ignorés).
 */
enum VenuePeriodMode: string
{
    case DISABLED = 'DISABLED';
    case BLANK = 'BLANK';
}
