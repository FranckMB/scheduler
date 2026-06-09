<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use Doctrine\ORM\EntityManagerInterface;

final class ScheduleResultImporter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, mixed> $solverOutput */
    public function import(Schedule $schedule, array $solverOutput): void
    {
        $existingSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy(['scheduleId' => $schedule->getId()]);

        $existingById = [];
        foreach ($existingSlots as $slot) {
            $existingById[$slot->getId()] = $slot;
        }

        $solverSlots = $this->deduplicateNoneSlots($solverOutput['slots'] ?? []);
        $solverSlotIds = array_fill_keys(array_keys($solverSlots), true);

        foreach ($existingSlots as $slot) {
            if (LockLevel::NONE === $slot->getLockLevel() && !isset($solverSlotIds[$slot->getId()])) {
                $this->entityManager->remove($slot);
            }
        }

        foreach ($solverSlots as $slotId => $slotData) {
            $existingSlot = $existingById[$slotId] ?? null;
            if (null !== $existingSlot && LockLevel::NONE !== $existingSlot->getLockLevel()) {
                continue;
            }

            $slot = $existingSlot ?? (new ScheduleSlotTemplate())->setId($slotId);
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
        if (!is_array($rawSlots)) {
            return [];
        }

        $slots = [];
        foreach ($rawSlots as $slot) {
            if (!is_array($slot) || ($slot['lockLevel'] ?? 'NONE') !== 'NONE') {
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
            ->setLockLevel(LockLevel::NONE);
    }

    private function parseStartTime(string $startTime): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!H:i', $startTime)
            ?: \DateTimeImmutable::createFromFormat('!H:i:s', $startTime);

        if (!$time instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(sprintf('Invalid solver slot startTime "%s".', $startTime));
        }

        return $time;
    }
}
