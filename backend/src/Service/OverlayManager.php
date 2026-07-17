<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ConstraintConflict;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ScheduleStatus;
use App\Repository\CalendarEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Centralises overlay-schedule deletion (palier B). An overlay schedule's slots
 * and diagnostics are plain guid columns with NO FK cascade — removing the
 * schedule alone would orphan them, so every deletion path goes through here.
 *
 * The period entry and its dated constraints are NOT removed: after its overlay
 * is deleted the period falls back to "signalée, non adaptée" (spec §6).
 */
final class OverlayManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Purge les artefacts d'une version et la supprime, SANS la promotion de
     * pointeur de purgeScheduleArtifacts(). Utilisé par la validation (ADR-0002
     * inv. 1 : choisir une version supprime ses sœurs), qui a déjà repointé tout le
     * monde sur la gagnante — y promouvoir « la version suivante » serait faux.
     *
     * L'entry qui pointerait encore la version détruite est tout de même nettoyée :
     * un overlay legacy (sans marqueur avant) peut être collecté comme sœur d'un
     * plan de saison, et le supprimer laisserait la période sur une ligne disparue.
     */
    public function deleteVersion(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());

        // Le lookup lit la DB, périmée vs UnitOfWork : l'appelant vient peut-être de
        // repointer cette entry sur la gagnante (en mémoire). L'instance managée
        // porte cette valeur en attente — re-vérifier avant d'effacer.
        $orphaned = $this->calendarEntryRepository->findOneByOverlayScheduleId($schedule->getId());
        if ($orphaned instanceof CalendarEntry && $orphaned->getOverlayScheduleId() === $schedule->getId()) {
            $orphaned->setOverlayScheduleId(null);
        }

        $this->entityManager->remove($schedule);
    }

    /**
     * Delete the overlay schedule of a period entry (if any) and clear the link.
     * Refuses (409) while the overlay is mid-generation — deleting it out from
     * under the worker would orphan the slots it is about to import — and, unless
     * $force, while its plan POINTS at it (en vigueur = read-only ; the entry-delete
     * path must not bypass the guard DELETE /api/schedules enforces). The
     * destructive reopen passes $force: the user explicitly confirmed destruction.
     */
    /** @return int the number of overlay versions removed */
    public function deleteOverlayForEntry(CalendarEntry $entry, bool $force = false): int
    {
        // planning-versions: a period may hold SEVERAL overlay versions — delete
        // them ALL (the pointer only names the active one). Guard every version
        // first so a refusal never leaves the period half-cleared.
        // ADR-0002 C4 : les versions d'une période sont celles de SON PLAN
        // (schedulePlanId via periodPlanId), plus le doublon schedule.calendarEntryId.
        // Une entrée sans plan (cutoff/mutualisation, inv. 9) → aucun overlay.
        $overlays = [];
        $planId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
        if (null !== $planId) {
            foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId]) as $schedule) {
                $overlays[$schedule->getId()] = $schedule;
            }
        }
        // Legacy overlays (pre-forward-marker) are only reachable via the reverse
        // pointer — include the active one if the forward set missed it.
        $activeId = $entry->getOverlayScheduleId();
        if (null !== $activeId && !isset($overlays[$activeId])) {
            $active = $this->entityManager->getRepository(Schedule::class)->find($activeId);
            if ($active instanceof Schedule) {
                $overlays[$activeId] = $active;
            }
        }
        foreach ($overlays as $schedule) {
            $this->assertNotGenerating($schedule);
            if (!$force && $this->schedulePlanProvisioner->isChosen($schedule->getId())) {
                throw new ConflictHttpException('Le planning de cette période est en vigueur (lecture seule). Rouvrez-le avant de supprimer la période.');
            }
        }
        // Atomique : le release du pointeur est un UPDATE brut qui s'auto-commit hors
        // transaction — sans ça, un flush en échec (purge des périodes échues, qui
        // catch et continue) laisserait le pointeur vidé alors que la version survit.
        $this->entityManager->wrapInTransaction(function () use ($overlays, $entry): void {
            foreach ($overlays as $schedule) {
                $this->purgeArtifacts($schedule->getId());
                $this->entityManager->remove($schedule);
            }

            $entry->setOverlayScheduleId(null);
            $this->entityManager->flush();
        });

        return \count($overlays);
    }

    /**
     * Purge a schedule's slots + diagnostics + conflicts and reset the linked
     * period entry. Used when a Schedule is deleted directly (DELETE /api/schedules).
     */
    public function purgeScheduleArtifacts(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());

        // ADR-0002 C4 : plus de forward link schedule.calendarEntryId. Le reverse
        // pointer suffit pour CE geste : findOneByOverlayScheduleId ne rend l'entrée
        // QUE si ce schedule en est l'overlay ACTIF — exactement la condition sous
        // laquelle on touche le pointeur. Une version non-active ne renvoyait de
        // toute façon aucune action (la garde ci-dessous l'excluait). Le reverse
        // pointer disparaît au lot D ; ce chemin sera revu avec lui.
        $entry = $this->calendarEntryRepository->findOneByOverlayScheduleId($schedule->getId());
        // Only touch the pointer when the ACTIVE version is deleted: with several
        // overlay versions per period, deleting a non-active one must not orphan
        // the period. Promote the most recent surviving version, else clear.
        if ($entry instanceof CalendarEntry && $entry->getOverlayScheduleId() === $schedule->getId()) {
            $entry->setOverlayScheduleId($this->newestOtherOverlayId($entry->getId(), $schedule->getId()));
        }
    }

    /** Most recent USABLE (COMPLETED) overlay version of the entry other than
     *  $excludeId, or null — never promotes a FAILED/DRAFT/PENDING version as the
     *  active plan (that would show an empty overlay in the cockpit). */
    private function newestOtherOverlayId(string $entryId, string $excludeId): ?string
    {
        // ADR-0002 C4 : les versions d'une période = celles de son plan (schedulePlanId).
        $planId = $this->schedulePlanProvisioner->periodPlanId($entryId);
        if (null === $planId) {
            return null;
        }
        $candidates = $this->entityManager->getRepository(Schedule::class)->findBy(
            ['schedulePlanId' => $planId],
            ['createdAt' => 'DESC'],
        );
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $excludeId) {
                continue;
            }
            if (ScheduleStatus::COMPLETED === $candidate->getStatus()) {
                return $candidate->getId();
            }
        }

        return null;
    }

    private function assertNotGenerating(Schedule $schedule): void
    {
        if (\in_array($schedule->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
            throw new ConflictHttpException('The overlay is being generated — wait for it to finish before deleting it.');
        }
    }

    private function purgeArtifacts(string $scheduleId): void
    {
        // ADR-0002 lot B1 (ADDITIF) : un pointeur ne doit jamais nommer une version
        // supprimée. Couvre les chemins ORM (DELETE /api/schedules, suppression de
        // période, reopen destructeur). PAS SeasonDataPurger, qui supprime en DQL de
        // masse — mais lui supprime aussi les plans, donc aucun pointeur ne survit.
        $this->schedulePlanProvisioner->releaseSchedule($scheduleId);

        // Per-row remove (not bulk DQL): keeps UnitOfWork + RLS consistent, same
        // reason CalendarEntryStateProcessor avoids bulk DELETE on `constraint`.
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId]) as $slot) {
            $this->entityManager->remove($slot);
        }
        foreach ($this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]) as $diagnostic) {
            $this->entityManager->remove($diagnostic);
        }
        foreach ($this->entityManager->getRepository(ConstraintConflict::class)->findBy(['scheduleId' => $scheduleId]) as $conflict) {
            $this->entityManager->remove($conflict);
        }
        foreach ($this->entityManager->getRepository(\App\Entity\ScheduleStructureSnapshot::class)->findBy(['scheduleId' => $scheduleId]) as $snapshot) {
            $this->entityManager->remove($snapshot);
        }
        // Les métriques du solveur pendent aussi à la version. Avant la bascule ce
        // chemin ne servait qu'aux overlays ; il supprime désormais les versions sœurs
        // à CHAQUE validation, donc les oublier accumulerait des métriques nommant des
        // plannings morts (et fausserait les agrégats superadmin).
        foreach ($this->entityManager->getRepository(\App\Entity\SolverMetric::class)->findBy(['scheduleId' => $scheduleId]) as $metric) {
            $this->entityManager->remove($metric);
        }
    }
}
