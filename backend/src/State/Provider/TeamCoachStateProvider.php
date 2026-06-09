<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamCoachResource;
use App\Entity\TeamCoach;

class TeamCoachStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return TeamCoach::class;
    }

    protected function mapEntityToOutput(object $entity): TeamCoachResource
    {
        return TeamCoachResource::fromEntity($entity);
    }
}
