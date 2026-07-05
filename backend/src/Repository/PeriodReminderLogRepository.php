<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PeriodReminderLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodReminderLog>
 */
final class PeriodReminderLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodReminderLog::class);
    }

    public function alreadySent(string $calendarEntryId, int $threshold): bool
    {
        return null !== $this->findOneBy(['calendarEntryId' => $calendarEntryId, 'threshold' => $threshold]);
    }
}
