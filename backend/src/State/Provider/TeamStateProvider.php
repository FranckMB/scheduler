<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamResource;
use App\Entity\Team;

class TeamStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Team::class;
    }

    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity);
    }
}
