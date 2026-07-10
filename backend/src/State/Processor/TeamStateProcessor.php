<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamResource;
use App\Dto\TeamInput;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;

/**
 * @extends AbstractStateProcessor<Team, TeamInput, TeamResource>
 */
class TeamStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Team::class;
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof Team) {
            $this->cascadeDeleter?->purgeChildrenOfTeam($entity);
        }
    }

    /**
     * @param TeamInput $input
     */
    protected function createEntityFromInput(object $input): Team
    {
        $entity = new Team;
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->tierOrder) {
            $entity->setTierOrder($input->tierOrder);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->gender) {
            $gender = Gender::tryFrom($input->gender);
            if (null !== $gender) {
                $entity->setGender($gender);
            }
        }
        $entity->setLevel(null !== $input->level ? TeamLevel::tryFrom($input->level) : null);
        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? true);
        $entity->setParentTeamId($input->parentTeamId);

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
        if (null !== $input->tierOrder) {
            $entity->setTierOrder($input->tierOrder);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->gender) {
            $gender = Gender::tryFrom($input->gender);
            if (null !== $gender) {
                $entity->setGender($gender);
            }
        }
        $entity->setLevel(null !== $input->level ? TeamLevel::tryFrom($input->level) : null);
        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? $entity->getIsActive());
        $entity->setParentTeamId($input->parentTeamId);
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity);
    }
}
