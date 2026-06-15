<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamTagAssignmentResource;
use App\Entity\TeamTagAssignment;

/**
 * @extends AbstractStateProvider<TeamTagAssignment, TeamTagAssignmentResource>
 */
class TeamTagAssignmentStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return TeamTagAssignment::class;
    }

    /**
     * @param TeamTagAssignment $entity
     */
    protected function mapEntityToOutput(object $entity): TeamTagAssignmentResource
    {
        return TeamTagAssignmentResource::fromEntity($entity);
    }
}
