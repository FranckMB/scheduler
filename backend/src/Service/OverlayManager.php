<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Repository\CalendarEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

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

    /** Delete the overlay schedule of a period entry (if any) and clear the link. */
    public function deleteOverlayForEntry(CalendarEntry $entry): void
    {
        $overlayId = $entry->getOverlayScheduleId();
        if (null === $overlayId) {
            return;
        }

        $schedule = $this->entityManager->getRepository(Schedule::class)->find($overlayId);
        if ($schedule instanceof Schedule) {
            $this->purgeArtifacts($overlayId);
            $this->entityManager->remove($schedule);
        }

        $entry->setOverlayScheduleId(null);
        $this->entityManager->flush();
    }

    /**
     * Purge a schedule's slots + diagnostics and reset any period entry pointing
     * at it. Used when a Schedule is deleted directly (DELETE /api/schedules).
     */
    public function purgeScheduleArtifacts(Schedule $schedule): void
    {
        $this->purgeArtifacts($schedule->getId());

        $entry = $this->calendarEntryRepository->findOneByOverlayScheduleId($schedule->getId());
        if ($entry instanceof CalendarEntry) {
            $entry->setOverlayScheduleId(null);
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
    }
}
