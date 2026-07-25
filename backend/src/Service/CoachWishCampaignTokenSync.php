<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\TeamCoach;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise les tokens coachs d'une campagne (feature #10, lot C2).
 *
 * À la création ET à chaque modification du périmètre (équipes), on INSÈRE les tokens
 * manquants — un par coach distinct (tous rôles) des équipes retenues. On n'en SUPPRIME
 * jamais : un lien déjà collé en WhatsApp ne doit pas devenir un 404. Un coach sorti du
 * périmètre garde son lien ; sa page calculera un périmètre vide (« aucune équipe
 * concernée »). Un coach supprimé → FK CASCADE emporte le token.
 */
final class CoachWishCampaignTokenSync
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function sync(CoachWishCampaign $campaign): void
    {
        $teamIds = $campaign->getTeamIds();
        if ([] === $teamIds) {
            return;
        }

        // Les coachs distincts des équipes retenues (MAIN comme ASSISTANT).
        $coachIds = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['teamId' => $teamIds]) as $link) {
            $coachIds[$link->getCoachId()] = true;
        }

        // Les tokens déjà émis pour cette campagne (par coach) — on ne recrée pas.
        $existing = [];
        foreach ($this->entityManager->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]) as $token) {
            $existing[$token->getCoachId()] = true;
        }

        foreach (array_keys($coachIds) as $coachId) {
            if (isset($existing[$coachId])) {
                continue;
            }
            $token = (new CoachWishToken)
                ->setCampaignId($campaign->getId())
                ->setCoachId($coachId)
                ->setClubId((string) $campaign->getClubId());
            $this->entityManager->persist($token);
        }

        $this->entityManager->flush();
    }
}
