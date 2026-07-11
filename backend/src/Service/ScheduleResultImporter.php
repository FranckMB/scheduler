<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class ScheduleResultImporter
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @param array<string, mixed> $solverOutput */
    public function import(Schedule $schedule, array $solverOutput): void
    {
        // Match THIS schedule's own rows only, keyed by PLACEMENT (team:venue:day:
        // start) — not by the engine's slot id. The engine id is deterministic on
        // the placement alone, so two schedules sharing a placement would collide
        // on the primary key (P0-5 theft). Placement-keying preserves a schedule's
        // HARD locks across regeneration while a fresh, per-schedule row id
        // (SlotIdScoper) lets sibling versions hold the same placement side by side.
        // This is transition-safe: a legacy row keyed on the old global id is still
        // matched by its placement, so no data migration is required.
        $existingSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy(['scheduleId' => $schedule->getId()]);

        $existingByPlacement = [];
        foreach ($existingSlots as $slot) {
            $existingByPlacement[$this->placementKey($slot->getTeamId(), $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'))] = $slot;
        }

        $solverSlots = $this->deduplicateNoneSlots($solverOutput['slots'] ?? []);
        $keptPlacements = [];
        foreach ($solverSlots as $slotData) {
            $keptPlacements[$this->placementKeyFromData($slotData)] = true;
        }

        foreach ($existingSlots as $slot) {
            $placement = $this->placementKey($slot->getTeamId(), $slot->getVenueId(), $slot->getDayOfWeek(), $slot->getStartTime()->format('H:i'));
            if (LockLevel::NONE === $slot->getLockLevel() && !isset($keptPlacements[$placement])) {
                $this->entityManager->remove($slot);
            }
        }

        foreach ($solverSlots as $engineId => $slotData) {
            $existingSlot = $existingByPlacement[$this->placementKeyFromData($slotData)] ?? null;
            // A HARD-locked row of this schedule is preserved untouched (its manual
            // metadata survives) — the engine re-emits it at the same placement.
            if (null !== $existingSlot && LockLevel::NONE !== $existingSlot->getLockLevel()) {
                continue;
            }

            $slot = $existingSlot ?? (new ScheduleSlotTemplate)->setId(SlotIdScoper::scope($schedule->getId(), (string) $engineId));
            $this->hydrateSlot($slot, $schedule, $slotData);

            if (null === $existingSlot) {
                $this->entityManager->persist($slot);
            }
        }

        $this->entityManager->flush();
    }

    /** Canonical placement key (team:venue:day:HH:MM) — the identity of a slot within a schedule. */
    private function placementKey(string $teamId, string $venueId, int $dayOfWeek, string $startTime): string
    {
        return \sprintf('%s:%s:%d:%s', $teamId, $venueId, $dayOfWeek, substr($startTime, 0, 5));
    }

    /** @param array<string, mixed> $slotData */
    private function placementKeyFromData(array $slotData): string
    {
        return $this->placementKey(
            (string) $slotData['teamId'],
            (string) $slotData['venueId'],
            (int) $slotData['dayOfWeek'],
            (string) $slotData['startTime'],
        );
    }

    /** @return array<string, mixed> */
    private function deduplicateNoneSlots(mixed $rawSlots): array
    {
        if (!\is_array($rawSlots)) {
            return [];
        }

        $slots = [];
        foreach ($rawSlots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }

            $lockLevel = ($slot['lockLevel'] ?? 'NONE');
            if ('NONE' !== $lockLevel && 'HARD' !== $lockLevel) {
                continue;
            }

            $slotId = (string) ($slot['id'] ?? '');
            if ('' === $slotId || isset($slots[$slotId])) {
                continue;
            }

            $slots[$slotId] = $slot;
        }

        return $slots;
    }

    /** @param array<string, mixed> $slotData */
    private function hydrateSlot(ScheduleSlotTemplate $slot, Schedule $schedule, array $slotData): void
    {
        $slot
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId((string) $slotData['teamId'])
            ->setVenueId((string) $slotData['venueId'])
            ->setCoachId(isset($slotData['coachId']) ? (string) $slotData['coachId'] : null)
            ->setDayOfWeek((int) $slotData['dayOfWeek'])
            ->setStartTime($this->parseStartTime((string) $slotData['startTime']))
            ->setDurationMinutes((int) $slotData['durationMinutes'])
            ->setLockLevel(LockLevel::tryFrom(strtoupper((string) ($slotData['lockLevel'] ?? 'NONE'))) ?? LockLevel::NONE);
    }

    private function parseStartTime(string $startTime): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!H:i', $startTime)
            ?: DateTimeImmutable::createFromFormat('!H:i:s', $startTime);

        if (!$time instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(\sprintf('Invalid solver slot startTime "%s".', $startTime));
        }

        return $time;
    }
}
