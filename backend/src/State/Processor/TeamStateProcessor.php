<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamResource;
use App\Dto\TeamInput;
use App\Entity\Team;

/**
 * @extends AbstractStateProcessor<Team, TeamInput, TeamResource>
 */
class TeamStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Team::class;
    }

    /**
     * @param TeamInput $input
     */
    protected function createEntityFromInput(object $input): Team
    {
        $entity = new Team();
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        $entity->setGender($input->gender);
        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? true);
        $entity->setParentTeamId($input->parentTeamId);
        $entity->setFfbbTeamId($input->ffbbTeamId);

        return $entity;
    }

    /**
     * @param Team      $entity
     * @param TeamInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        $entity->setGender($input->gender);
        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? $entity->getIsActive());
        $entity->setParentTeamId($input->parentTeamId);
        $entity->setFfbbTeamId($input->ffbbTeamId);
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity);
    }
}
