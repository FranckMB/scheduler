<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class ManualEditService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function applyPermanentConstraint(
        ScheduleSlotTemplate $slot,
        string $type,
        ?string $reason = null,
        ?string $createdBy = null,
    ): Constraint {
        $constraint = new Constraint;
        $constraint
            ->setClubId($slot->getClubId())
            ->setSeasonId($slot->getSeasonId())
            ->setName($reason ?? 'Manual edit constraint')
            ->setScope(ConstraintScope::TEAM)
            ->setScopeTargetId($slot->getTeamId())
            ->setFamily(ConstraintFamily::TIME)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig([
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i:s'),
                'endTime' => $this->calculateEndTime($slot->getStartTime(), $slot->getDurationMinutes())->format('H:i:s'),
                'venueId' => $slot->getVenueId(),
                'type' => $type,
                'reason' => $reason,
            ])
            ->setSource('manual_edit')
            ->setCreatedBy($createdBy);

        $this->entityManager->persist($constraint);
        $this->entityManager->flush();

        return $constraint;
    }

    public function applyLock(ScheduleSlotTemplate $slot, LockLevel $lockLevel): void
    {
        $slot->setLockLevel($lockLevel);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function applyOneTimeUpdate(ScheduleSlotTemplate $slot, array $data): void
    {
        $dayOfWeek = isset($data['dayOfWeek']) ? (int) $data['dayOfWeek'] : $slot->getDayOfWeek();
        $startTime = isset($data['startTime']) && $data['startTime'] instanceof DateTimeImmutable
            ? $data['startTime']
            : $slot->getStartTime();
        $durationMinutes = isset($data['durationMinutes']) ? (int) $data['durationMinutes'] : $slot->getDurationMinutes();
        $venueId = isset($data['venueId']) ? (string) $data['venueId'] : $slot->getVenueId();
        $coachId = \array_key_exists('coachId', $data) ? $data['coachId'] : $slot->getCoachId();

        $conflicts = $this->findConflicts($slot, $dayOfWeek, $startTime, $durationMinutes, $venueId, $coachId);

        if ([] !== $conflicts) {
            throw new InvalidArgumentException(\sprintf('Conflict detected with slot(s): %s.', implode(', ', array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $conflicts))));
        }

        $slot
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            ->setDurationMinutes($durationMinutes)
            ->setVenueId($venueId)
            ->setCoachId($coachId);

        $this->entityManager->flush();
    }

    /**
     * @return ScheduleSlotTemplate[]
     */
    private function findConflicts(
        ScheduleSlotTemplate $slot,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        int $durationMinutes,
        string $venueId,
        ?string $coachId,
    ): array {
        $otherSlots = $this->entityManager
            ->getRepository(ScheduleSlotTemplate::class)
            ->findBy(['scheduleId' => $slot->getScheduleId()]);

        $conflicts = [];
        $newEnd = $this->calculateEndTime($startTime, $durationMinutes);

        foreach ($otherSlots as $other) {
            if ($other->getId() === $slot->getId()) {
                continue;
            }

            if ($other->getDayOfWeek() !== $dayOfWeek) {
                continue;
            }

            $otherEnd = $this->calculateEndTime($other->getStartTime(), $other->getDurationMinutes());

            $timeOverlap = $startTime < $otherEnd && $other->getStartTime() < $newEnd;

            if (!$timeOverlap) {
                continue;
            }

            if ($other->getVenueId() === $venueId) {
                $conflicts[] = $other;

                continue;
            }

            if (null !== $coachId && $other->getCoachId() === $coachId) {
                $conflicts[] = $other;
            }
        }

        return $conflicts;
    }

    private function calculateEndTime(DateTimeImmutable $startTime, int $durationMinutes): DateTimeImmutable
    {
        return $startTime->modify(\sprintf('+%d minutes', $durationMinutes));
    }
}
