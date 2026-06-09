<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamResource;
use App\Entity\Team;

/**
 * @extends AbstractStateProvider<Team, TeamResource>
 */
class TeamStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Team::class;
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity);
    }
}
