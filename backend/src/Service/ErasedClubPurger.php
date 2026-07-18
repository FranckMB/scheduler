<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\TeamTag;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RGPD — purge du workspace d'un club effacé (délai de grâce échu).
 *
 * Vide TOUTES les données d'exploitation du tenant : chaque saison via
 * SeasonDataPurger (ligne Season incluse), puis les entités club-scoped sans
 * saison (TeamTag, SportCategory custom) et les memberships ClubUser.
 *
 * ÉPARGNE l'identité publique FFBB du club (décision fondateur 2026-07-11) :
 * nom, ffbbClubCode, logo, ligue/comité, contacts président/correspondant tels
 * que publiés par la FFBB (source FfbbClubPopulator, base légale intérêt
 * légitime — organisation des rencontres, futur annuaire adverse, win-back).
 * Les comptes User des membres NE sont PAS touchés : ils appartiennent à leurs
 * titulaires (responsable de traitement = ClubScheduler), qui peuvent les
 * effacer eux-mêmes via DELETE /api/me.
 *
 * Tourne sous le GUC du club (posé par l'appelant, pattern PurgeSeasonsCommand).
 */
final class ErasedClubPurger
{
    use DisablesTenantFilters;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly AuditTrail $auditTrail,
    ) {}

    /** @return int nombre de lignes supprimées (workspace complet) */
    public function purge(Club $club): int
    {
        $clubId = $club->getId();
        $deleted = 0;

        // 1. Toutes les saisons du club, ligne Season comprise.
        $this->disableTenantFilters($this->entityManager);
        $seasons = $this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId]);
        foreach ($seasons as $season) {
            $deleted += $this->seasonDataPurger->purge($clubId, $season->getId(), deleteSeasonRow: true) + 1;
        }

        // 2. Club-scoped sans saison. SeasonDataPurger::purge fait un clear()
        //    final → les filtres Doctrine doivent être re-désactivés.
        $this->disableTenantFilters($this->entityManager);
        foreach ([
            // SolverMetric est APPEND-ONLY (SA2-stats, 2026-07-18) : ni la validation ni
            // le reset de saison ne le purgent plus. CE chemin est donc sa SEULE porte de
            // sortie — « seule l'identité FFBB survit » doit être vrai à la lettre, et la
            // suppression par clubId emporte tout l'historique, rattaché ou orphelin.
            \App\Entity\SolverMetric::class,
            TeamTag::class, SportCategory::class, ClubUser::class,
        ] as $entityClass) {
            $deleted += (int) $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'e')
                ->where('e.clubId = :clubId')
                ->setParameter('clubId', $clubId)
                ->getQuery()
                ->execute();
        }

        // 3. La fiche club survit (identité publique FFBB : nom, code, logo,
        //    ligue/comité, contacts FFBB) — mais l'état d'ABONNEMENT n'est pas
        //    de l'identité publique : plan, cycle de facturation et compteurs
        //    sont remis à zéro (revue sécurité PR-1 — « seule l'identité FFBB
        //    survit » doit être vrai à la lettre).
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if ($club instanceof Club) {
            $club->setErasureScheduledAt(null);
            $club->setUnsubscribedAt(new DateTimeImmutable);
            $club->setOnboardingCompleted(false);
            $club->setPlanId(null);
            $club->setBillingCycle(null);
            $club->setPlanExpiresAt(null);
            $club->setGenerationCountSeason(0);
            $this->entityManager->flush();
        }
        $this->entityManager->clear();

        // Audit APRÈS le clear (l'insert DBAL ne touche pas l'unit of work).
        $this->auditTrail->record(\App\Enum\AuditAction::CLUB_PURGED, null, $clubId, 'Club', $clubId, ['rowsDeleted' => $deleted]);

        return $deleted;
    }
}
