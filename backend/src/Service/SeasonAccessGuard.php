<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Read-only guard for archived seasons (spec transition-de-saison §3): once a
 * season rolls into the past (N-1 and older), it is frozen — no write may
 * target it. Two independent signals, and the STRICTER of the two wins:
 *
 *  1. La saison SÉLECTIONNÉE : le listener a résolu et validé le header
 *     `X-Season-Id` (ou la saison courante) et estampillé `_season_readonly`.
 *  2. SEC-13 — la saison de la RESSOURCE ciblée (`$targetSeasonId`), résolue hors
 *     filtres par {@see WriteTargetSeasonResolver}. Sans header, la saison
 *     sélectionnée est la courante (writable), mais l'écriture peut viser une
 *     ressource d'une saison archivée : le header seul ne suffit pas. Cible `null`
 *     (écriture de collection, ou cible cachée par la RLS) → seul (1) s'applique,
 *     comportement d'origine intégralement préservé. Le garde ne devient JAMAIS un
 *     oracle d'existence : une saison introuvable ne 409 pas.
 *
 * Le read-only de la cible se calcule avec l'existant : la saison chargée par id
 * (mêmes filtres que le reste : `Season` n'a pas de `season_id`, le SeasonFilter ne
 * la touche pas ; le TenantFilter/RLS la bornent au club) + ses sœurs, passées au
 * pur {@see SeasonResolver::isReadonlyAmong} — clock-aware, donc le simulateur dev
 * décale toujours le pivot 15-juillet.
 *
 * 409 (not 423) mirrors the VALIDATED-lock idiom (ManualEditController), which
 * the frontend toast pipeline already surfaces.
 */
final class SeasonAccessGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {}

    public function assertWritable(?Request $request, ?string $targetSeasonId = null): void
    {
        // (1) Saison sélectionnée archivée — comportement historique, inchangé.
        if (true === $request?->attributes->get('_season_readonly')) {
            throw new ConflictHttpException('This season is archived (read-only).');
        }

        // (2) SEC-13 — saison de la RESSOURCE ciblée archivée, même si la sélection
        // (header/saison courante) ne l'est pas.
        if (null === $targetSeasonId) {
            return;
        }

        $season = $this->entityManager->find(Season::class, $targetSeasonId);
        if (!$season instanceof Season) {
            return; // introuvable / cachée par la RLS → repli, jamais un oracle d'existence.
        }

        $siblings = $this->entityManager->getRepository(Season::class)
            ->findBy(['clubId' => $season->getClubId()], ['startDate' => 'ASC']);
        if (SeasonResolver::isReadonlyAmong($season, $siblings, $this->clock->now())) {
            throw new ConflictHttpException('This season is archived (read-only).');
        }
    }
}
