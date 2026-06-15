<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamTagResource;
use App\Entity\TeamTag;

/**
 * @extends AbstractStateProvider<TeamTag, TeamTagResource>
 */
class TeamTagStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return TeamTag::class;
    }

    /**
     * @param TeamTag $entity
     */
    protected function mapEntityToOutput(object $entity): TeamTagResource
    {
        return TeamTagResource::fromEntity($entity);
    }
}
