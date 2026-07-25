<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoachWishToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoachWishToken>
 */
class CoachWishTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachWishToken::class);
    }

    /**
     * Lookup par le token en clair. La policy RLS `coach_wish_token_read` (SELECT USING true)
     * autorise cette lecture AVANT que le GUC `app.club_id` soit posé — c'est le token qui
     * porte le club, on ne peut le scoper qu'après l'avoir lu.
     */
    public function findOneByToken(string $token): ?CoachWishToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @return list<CoachWishToken>
     */
    public function findByCampaign(string $campaignId): array
    {
        return $this->findBy(['campaignId' => $campaignId]);
    }
}
