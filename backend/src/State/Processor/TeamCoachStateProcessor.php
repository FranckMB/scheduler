<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamCoachResource;
use App\Dto\TeamCoachInput;
use App\Entity\TeamCoach;

/**
 * @extends AbstractStateProcessor<TeamCoach, TeamCoachInput, TeamCoachResource>
 */
class TeamCoachStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamCoach::class;
    }

    /**
     * @param TeamCoachInput $input
     */
    protected function createEntityFromInput(object $input): TeamCoach
    {
        $entity = new TeamCoach();
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->role) {
            $entity->setRole($input->role);
        }
        if (null !== $input->isRequired) {
            $entity->setIsRequired($input->isRequired);
        }

        return $entity;
    }

    /**
     * @param TeamCoach      $entity
     * @param TeamCoachInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->role) {
            $entity->setRole($input->role);
        }
        if (null !== $input->isRequired) {
            $entity->setIsRequired($input->isRequired);
        }
    }

    /**
     * @param TeamCoach $entity
     */
    protected function mapEntityToOutput(object $entity): TeamCoachResource
    {
        return TeamCoachResource::fromEntity($entity);
    }
}
