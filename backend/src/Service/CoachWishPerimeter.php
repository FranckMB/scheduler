<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWishCampaign;
use App\Entity\TeamCoach;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Source UNIQUE du périmètre d'une campagne de collecte (feature #10) : les coachs distincts
 * des équipes actuellement retenues (TeamCoach ∩ `teamIds`, MAIN comme ASSISTANT).
 *
 * Partagé par le presenter (liste cockpit), le token-sync (qui reçoit un token) et le digest
 * (qui figure dans l'email) — sans ça, trois copies de la règle divergeraient et l'écran et
 * l'email listeraient des coachs différents (la dérive que la revue #10 C3 a corrigée).
 */
final class CoachWishPerimeter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Coachs du périmètre courant, en set indexé pour un test d'appartenance O(1).
     *
     * @return array<string, true>
     */
    public function coachIdSet(CoachWishCampaign $campaign): array
    {
        $teamIds = $campaign->getTeamIds();
        if ([] === $teamIds) {
            return [];
        }
        $set = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['teamId' => $teamIds]) as $link) {
            $set[$link->getCoachId()] = true;
        }

        return $set;
    }
}
