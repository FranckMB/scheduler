<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

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
        // Le gestionnaire épingle (ou déverrouille) à la main : origine MANUAL tant qu'il
        // y a un verrou, NULL quand il le retire (NONE = pas de verrou, pas d'origine).
        $slot->setLockOrigin(LockLevel::NONE === $lockLevel ? null : LockOrigin::MANUAL);
        $this->entityManager->flush();
    }

    private function calculateEndTime(DateTimeImmutable $startTime, int $durationMinutes): DateTimeImmutable
    {
        return $startTime->modify(\sprintf('+%d minutes', $durationMinutes));
    }
}
