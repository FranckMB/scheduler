<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\TeamLinkResource;
use App\Dto\TeamLinkInput;
use App\Entity\Team;
use App\Entity\TeamLink;
use App\Enum\TeamLinkType;

/**
 * Wizard-surface structure entity (VenueMatchWindow idiom): not management
 * gated. Normalizes the couple (teamAId < teamBId) so A–B ≡ B–A.
 *
 * @extends AbstractStateProcessor<TeamLink, TeamLinkInput, TeamLinkResource>
 */
class TeamLinkStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamLink::class;
    }

    /**
     * @param TeamLinkInput $input
     */
    protected function createEntityFromInput(object $input): TeamLink
    {
        $entity = new TeamLink;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param TeamLink      $entity
     * @param TeamLinkInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param TeamLink $entity
     */
    protected function mapEntityToOutput(object $entity): TeamLinkResource
    {
        return TeamLinkResource::fromEntity($entity);
    }

    private function applyInput(TeamLink $entity, TeamLinkInput $input): void
    {
        $teamAId = $input->teamAId;
        $teamBId = $input->teamBId;
        if (null === $teamAId || null === $teamBId) {
            throw new ValidationException('Both teams are required.');
        }
        if ($teamAId === $teamBId) {
            throw new ValidationException('A team cannot be linked to itself.');
        }
        // SYMMETRIC couple: normalize so SM1–SM2 and SM2–SM1 are the SAME row
        // (the DB unique then makes the duplicate a clean 422 below).
        if (strcasecmp($teamAId, $teamBId) > 0) {
            [$teamAId, $teamBId] = [$teamBId, $teamAId];
        }
        $entity->setTeamAId($teamAId);
        $entity->setTeamBId($teamBId);
        if (null !== $input->linkType) {
            $entity->setLinkType(TeamLinkType::from($input->linkType));
        }

        // Foreign/unknown teams resolve to null through the tenant+season
        // filters → 422 (`findOneBy`, never `find()` — leçon PR B).
        $teamRepository = $this->entityManager->getRepository(Team::class);
        foreach ([$teamAId, $teamBId] as $teamId) {
            if (!$teamRepository->findOneBy(['id' => $teamId]) instanceof Team) {
                throw new ValidationException('Unknown team for this club.');
            }
        }
        // One couple = one link (readable 422; the DB unique is the backstop).
        $existing = $this->entityManager->getRepository(TeamLink::class)->findOneBy([
            'teamAId' => $teamAId,
            'teamBId' => $teamBId,
        ]);
        if ($existing instanceof TeamLink && $existing->getId() !== $entity->getId()) {
            throw new ValidationException('These two teams are already linked — edit the existing link.');
        }
    }
}
