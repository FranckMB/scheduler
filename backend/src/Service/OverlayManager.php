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
        $overlayId = $entry->getOverlayScheduleId();
        if (null === $overlayId) {
            return;
        }

        $schedule = $this->entityManager->getRepository(Schedule::class)->find($overlayId);
        if ($schedule instanceof Schedule) {
            $this->assertNotGenerating($schedule);
            if (!$force && ScheduleStatus::VALIDATED === $schedule->getStatus()) {
                throw new ConflictHttpException('The period plan is validated (read-only). Reopen it before deleting the period.');
            }
            $this->purgeArtifacts($overlayId);
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
        if ($entry instanceof CalendarEntry) {
            $entry->setOverlayScheduleId(null);
        }
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
    }
}
