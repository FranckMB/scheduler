<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WriteTargetSeasonResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marker for custom controllers whose action WRITES season-scoped data
 * (schedules, teams, constraints…). SeasonReadonlyGuardListener refuses these
 * with a 409 when the targeted season is archived (read-only), mirroring the
 * SeasonAccessGuard applied to the generic API Platform processors.
 *
 * Do NOT implement on controllers that touch club/user-level data only
 * (appearance, profile) — those stay editable whatever season is selected.
 */
interface SeasonScopedWriteInterface
{
    /**
     * La saison de la RESSOURCE que cette écriture cible (résolue hors filtres, via
     * {@see WriteTargetSeasonResolver}), ou `null` pour une écriture de
     * COLLECTION — le header/saison courante gouverne alors (comportement d'origine).
     *
     * SEC-13 : le marqueur ne compile plus sans répondre « quelle est la saison de ta
     * cible ? ». Retourner `null` reste permis, mais devient un choix écrit et revu.
     */
    public function writeTargetSeasonId(Request $request): ?string;
}
