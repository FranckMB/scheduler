<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use Doctrine\ORM\EntityManagerInterface;

final class ManualEditService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function applyLock(ScheduleSlotTemplate $slot, LockLevel $lockLevel): void
    {
        $slot->setLockLevel($lockLevel);
        // Le gestionnaire épingle (ou déverrouille) à la main : origine MANUAL tant qu'il
        // y a un verrou, NULL quand il le retire (NONE = pas de verrou, pas d'origine).
        $slot->setLockOrigin(LockLevel::NONE === $lockLevel ? null : LockOrigin::MANUAL);
        $this->entityManager->flush();
    }
}
