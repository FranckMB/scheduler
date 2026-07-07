<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TransitionReminderLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransitionReminderLog>
 */
final class TransitionReminderLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransitionReminderLog::class);
    }

    public function alreadySent(string $seasonId, int $threshold): bool
    {
        return null !== $this->findOneBy(['seasonId' => $seasonId, 'threshold' => $threshold]);
    }
}
