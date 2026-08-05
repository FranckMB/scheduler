<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubCreationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubCreationRequest>
 */
final class ClubCreationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubCreationRequest::class);
    }

    public function findPendingByToken(string $token): ?ClubCreationRequest
    {
        return $this->findOneBy(['token' => $token, 'status' => ClubCreationRequest::STATUS_PENDING]);
    }

    /** La demande VIVANTE d'un utilisateur (au plus une : le register la réutilise). */
    public function findPendingByUser(string $userId): ?ClubCreationRequest
    {
        return $this->findOneBy(['userId' => $userId, 'status' => ClubCreationRequest::STATUS_PENDING]);
    }

    /** @return list<ClubCreationRequest> */
    public function findPendingByAra(string $ara): array
    {
        return $this->findBy(['ara' => $ara, 'status' => ClubCreationRequest::STATUS_PENDING]);
    }
}
