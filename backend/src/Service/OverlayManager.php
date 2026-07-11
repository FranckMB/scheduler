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
    ) {}

    /**
     * Delete the overlay schedule of a period entry (if any) and clear the link.
     * Refuses (409) while the overlay is mid-generation — deleting it out from
     * under the worker would orphan the slots it is about to import — and, unless
     * $force, while it is VALIDATED (read-only means read-only; the entry-delete
     * path must not bypass the guard DELETE /api/schedules enforces). The
     * destructive reopen passes $force: the user explicitly confirmed destruction.
     */
    public function deleteOverlayForEntry(CalendarEntry $entry, bool $force = false): void
    {
        // planning-versions: a period may hold SEVERAL overlay versions — delete
        // them ALL (the pointer only names the active one). Guard every version
        // first so a refusal never leaves the period half-cleared.
        $overlays = [];
        foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['calendarEntryId' => $entry->getId()]) as $schedule) {
            $overlays[$schedule->getId()] = $schedule;
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
            if (!$force && ScheduleStatus::VALIDATED === $schedule->getStatus()) {
                throw new ConflictHttpException('The period plan is validated (read-only). Reopen it before deleting the period.');
            }
        }
        foreach ($overlays as $schedule) {
            $this->purgeArtifacts($schedule->getId());
            $this->entityManager->remove($schedule);
        }

        $entry->setOverlayScheduleId(null);
        $this->entityManager->flush();
    }

    /**
     * Purge a schedule's slots + diagnostics + conflicts and reset the linked
     * period entry. Used when a Schedule is deleted directly (DELETE /api/schedules).
     */
    public function purgeScheduleArtifacts(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());

        // Forward link on the schedule identifies the entry directly; fall back
        // to the reverse lookup for pre-palier-B rows with no marker.
        $entry = null !== $schedule->getCalendarEntryId()
            ? $this->entityManager->getRepository(CalendarEntry::class)->find($schedule->getCalendarEntryId())
            : $this->calendarEntryRepository->findOneByOverlayScheduleId($schedule->getId());
        // Only touch the pointer when the ACTIVE version is deleted: with several
        // overlay versions per period, deleting a non-active one must not orphan
        // the period. Promote the most recent surviving version, else clear.
        if ($entry instanceof CalendarEntry && $entry->getOverlayScheduleId() === $schedule->getId()) {
            $entry->setOverlayScheduleId($this->newestOtherOverlayId($entry->getId(), $schedule->getId()));
        }
    }

    /** Most recent non-archived overlay version of the entry other than $excludeId, or null. */
    private function newestOtherOverlayId(string $entryId, string $excludeId): ?string
    {
        $candidates = $this->entityManager->getRepository(Schedule::class)->findBy(
            ['calendarEntryId' => $entryId],
            ['createdAt' => 'DESC'],
        );
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $excludeId || ScheduleStatus::ARCHIVED === $candidate->getStatus()) {
                continue;
            }

            return $candidate->getId();
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
    }
}
