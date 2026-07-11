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
        // Match against THIS schedule's own rows only. The engine returns a
        // placement-deterministic id shared by every schedule with that placement;
        // scoping it per schedule (SlotIdScoper) makes each version's row unique,
        // so loading other schedules' slots would only re-open the P0-5 theft.
        $existingSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy(['scheduleId' => $schedule->getId()]);

        $existingById = [];
        foreach ($existingSlots as $slot) {
            $existingById[$slot->getId()] = $slot;
        }

        // Re-key the solver output by the PER-SCHEDULE id (uuid5(scheduleId:engineId)):
        // deterministic within the schedule (HARD-lock rows re-match on regeneration),
        // distinct across schedules (no cross-version collision).
        $solverSlots = [];
        foreach ($this->deduplicateNoneSlots($solverOutput['slots'] ?? []) as $engineId => $slotData) {
            $solverSlots[SlotIdScoper::scope($schedule->getId(), (string) $engineId)] = $slotData;
        }

        foreach ($existingSlots as $slot) {
            if (LockLevel::NONE === $slot->getLockLevel() && !isset($solverSlots[$slot->getId()])) {
                $this->entityManager->remove($slot);
            }
        }

        foreach ($solverSlots as $slotId => $slotData) {
            $existingSlot = $existingById[$slotId] ?? null;
            // A HARD-locked row of this schedule is preserved untouched (its manual
            // metadata survives) — only the engine re-emits it with the same placement.
            if (null !== $existingSlot && LockLevel::NONE !== $existingSlot->getLockLevel()) {
                continue;
            }

            $slot = $existingSlot ?? (new ScheduleSlotTemplate)->setId($slotId);
            $this->hydrateSlot($slot, $schedule, $slotData);

            if (null === $existingSlot) {
                $this->entityManager->persist($slot);
            }
        }

        $this->entityManager->flush();
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
