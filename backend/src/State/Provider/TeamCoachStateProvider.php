<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamCoachResource;
use App\Entity\TeamCoach;

/**
 * @extends AbstractStateProvider<TeamCoach, TeamCoachResource>
 */
class TeamCoachStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return TeamCoach::class;
    }

    /**
     * @param TeamCoach $entity
     */
    protected function mapEntityToOutput(object $entity): TeamCoachResource
    {
        return TeamCoachResource::fromEntity($entity);
    }
}
