<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VenueMatchWindow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenueMatchWindow>
 */
final class VenueMatchWindowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenueMatchWindow::class);
    }
}
