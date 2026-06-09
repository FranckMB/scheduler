<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduleSlotTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduleSlotTemplate>
 */
final class ScheduleSlotTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleSlotTemplate::class);
    }
}
