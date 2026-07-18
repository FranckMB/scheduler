<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ConstraintConflict;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ScheduleStatus;
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
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Purge les artefacts d'une version et la supprime. Utilisé par la validation
     * (ADR-0002 inv. 1 : choisir une version supprime ses sœurs). Si la version
     * détruite était celle que pointe son plan, purgeArtifacts (→ releaseSchedule)
     * a déjà relâché le pointeur — plus de pointeur inverse à nettoyer (lot D-b).
     */
    public function deleteVersion(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());
        $this->entityManager->remove($schedule);
    }

    /**
     * Delete every overlay version of a period entry. Refuses (409) while an overlay
     * is mid-generation — deleting it out from under the worker would orphan the slots
     * it is about to import — and, unless $force, while its plan POINTS at one (en
     * vigueur = read-only ; the entry-delete path must not bypass the guard DELETE
     * /api/schedules enforces). The destructive reopen passes $force: the user
     * explicitly confirmed destruction.
     */
    /** @return int the number of overlay versions removed */
    public function deleteOverlayForEntry(CalendarEntry $entry, bool $force = false): int
    {
        // planning-versions: a period may hold SEVERAL overlay versions — delete
        // them ALL. Guard every version first so a refusal never leaves the period
        // half-cleared. ADR-0002 : les versions d'une période sont celles de SON PLAN
        // (schedulePlanId via periodPlanId). Une entrée sans plan (cutoff/mutualisation,
        // inv. 9) → aucun overlay.
        $overlays = [];
        $planId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
        if (null !== $planId) {
            foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId]) as $schedule) {
                $overlays[$schedule->getId()] = $schedule;
            }
        }
        foreach ($overlays as $schedule) {
            $this->assertNotGenerating($schedule);
            if (!$force && $this->schedulePlanProvisioner->isChosen($schedule->getId())) {
                throw new ConflictHttpException('Le planning de cette période est en vigueur (lecture seule). Rouvrez-le avant de supprimer la période.');
            }
        }
        // purgeArtifacts (→ releaseSchedule) relâche le pointeur du plan pour la version
        // choisie : plus de pointeur inverse d'entrée à vider (lot D-b).
        $this->entityManager->wrapInTransaction(function () use ($overlays): void {
            foreach ($overlays as $schedule) {
                $this->purgeArtifacts($schedule->getId());
                $this->entityManager->remove($schedule);
            }

            $this->entityManager->flush();
        });

        return \count($overlays);
    }

    /**
     * Purge a schedule's slots + diagnostics + conflicts. Used when a Schedule is
     * deleted directly (DELETE /api/schedules). Si cette version était celle que
     * pointe son plan, purgeArtifacts (→ releaseSchedule) a déjà relâché le pointeur :
     * plus d'auto-promotion ni de pointeur inverse d'entrée (lot D-b — « actif » =
     * plan.chosenScheduleId, dérivé, et supprimer le choisi rend le plan non validé).
     */
    public function purgeScheduleArtifacts(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());
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
