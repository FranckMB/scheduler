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
        $existingSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy(['scheduleId' => $schedule->getId()]);

        $seasonSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy([
                'clubId' => $schedule->getClubId(),
                'seasonId' => $schedule->getSeasonId(),
            ]);

        $existingById = [];
        foreach ($existingSlots as $slot) {
            $existingById[$slot->getId()] = $slot;
        }

        foreach ($seasonSlots as $slot) {
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
            if (
                null !== $existingSlot
                && LockLevel::NONE !== $existingSlot->getLockLevel()
                && $existingSlot->getScheduleId() === $schedule->getId()
            ) {
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
