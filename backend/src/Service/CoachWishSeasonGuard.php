<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWishCampaign;
use App\Entity\Season;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Source UNIQUE de « la saison de cette campagne est-elle en lecture seule ? » (feature #10).
 *
 * Le read-only est DÉRIVÉ DU CALENDRIER (`SeasonResolver::isReadonlyAmong`, pivot 15-juillet) —
 * le statut `archived` n'est PAS posé au roulement, c'est un flag manuel qu'on ajoute. Partagé
 * par la page publique (410) et les actions management (409) : deux copies de cette règle
 * avaient déjà divergé (la 1re version testait le seul statut → écriture dans une saison gelée,
 * revue sécurité #10 C2). Un seul foyer, pas de récidive.
 */
final class CoachWishSeasonGuard
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonRepository $seasonRepository,
        private readonly ClockInterface $clock,
    ) {}

    public function isReadonly(CoachWishCampaign $campaign): bool
    {
        $season = $this->entityManager->getRepository(Season::class)->find($campaign->getSeasonId());
        if (null === $season) {
            return true; // fail-closed
        }
        if ($season->getStatus()->isFrozen()) {
            return true; // archivage/clôture manuels
        }
        $clubId = $campaign->getClubId();
        if (null === $clubId) {
            return true; // campagne sans club (impossible en pratique) → fail-closed
        }

        return SeasonResolver::isReadonlyAmong($season, $this->seasonRepository->findAllByClubId($clubId), $this->clock->now());
    }
}
