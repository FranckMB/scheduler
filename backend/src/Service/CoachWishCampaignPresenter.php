<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\CoachWishCampaignResource;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\TeamCoach;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enrichit une campagne en ressource de sortie (feature #10, lot C2) : compteurs du radar
 * + liste des coachs du périmètre COURANT avec leur token. Partagé entre le provider (GET)
 * et le processor (POST/PUT), pour une seule source de vérité de la présentation.
 *
 * Le périmètre COURANT peut différer des tokens émis : un coach sorti du périmètre garde
 * son token (jamais supprimé) mais n'apparaît plus ici — les compteurs et la liste ne
 * comptent que les coachs des équipes actuellement retenues.
 */
final class CoachWishCampaignPresenter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function toResource(CoachWishCampaign $campaign): CoachWishCampaignResource
    {
        $dto = new CoachWishCampaignResource;
        $dto->id = $campaign->getId();
        $dto->calendarEntryId = $campaign->getCalendarEntryId();
        $dto->deadline = $campaign->getDeadline()->format('Y-m-d');
        $dto->weeks = $campaign->getWeeks();
        $dto->teamIds = $campaign->getTeamIds();
        $dto->lastReminderAt = $campaign->getLastReminderAt()?->format(DateTimeInterface::ATOM);

        // Coachs distincts du périmètre courant (les équipes actuellement retenues).
        $perimeterCoachIds = [];
        if ([] !== $campaign->getTeamIds()) {
            foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['teamId' => $campaign->getTeamIds()]) as $link) {
                $perimeterCoachIds[$link->getCoachId()] = true;
            }
        }

        // Les tokens de cette campagne, indexés par coach (respondedAt).
        $tokenByCoach = [];
        foreach ($this->entityManager->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]) as $token) {
            $tokenByCoach[$token->getCoachId()] = $token;
        }

        // Chargement groupé des coachs du périmètre (une requête) — évite un find() par coach
        // (N+1 amplifié par le provider de collection qui projette toutes les campagnes).
        $coachById = [];
        if ([] !== $perimeterCoachIds) {
            foreach ($this->entityManager->getRepository(Coach::class)->findBy(['id' => array_keys($perimeterCoachIds)]) as $coach) {
                $coachById[$coach->getId()] = $coach;
            }
        }

        $coaches = [];
        $responded = 0;
        foreach (array_keys($perimeterCoachIds) as $coachId) {
            $token = $tokenByCoach[$coachId] ?? null;
            if (null === $token) {
                continue; // token pas encore synchronisé (transitoire) — sans lien, on n'affiche rien
            }
            $coach = $coachById[$coachId] ?? null;
            if (!$coach instanceof Coach) {
                continue;
            }
            $respondedAt = $token->getRespondedAt();
            if (null !== $respondedAt) {
                ++$responded;
            }
            $coaches[] = [
                'coachId' => $coachId,
                'firstName' => $coach->getFirstName(),
                'lastName' => $coach->getLastName(),
                'email' => $coach->getEmail(),
                'token' => $token->getToken(),
                'respondedAt' => $respondedAt?->format(DateTimeInterface::ATOM),
                'sentAt' => $token->getSentAt()?->format(DateTimeInterface::ATOM),
            ];
        }

        $dto->coaches = $coaches;
        $dto->totalCoachCount = \count($coaches);
        $dto->respondedCoachCount = $responded;
        // « À traiter » de CETTE campagne : les doléances non traitées de ses ÉQUIPES, pas de
        // toute la période (une saisie « au nom de » sur une équipe hors campagne ne compte pas).
        $dto->openWishCount = [] === $campaign->getTeamIds()
            ? 0
            : (int) $this->entityManager->getRepository(CoachWish::class)
                ->count(['calendarEntryId' => $campaign->getCalendarEntryId(), 'teamId' => $campaign->getTeamIds(), 'done' => false]);

        return $dto;
    }
}
