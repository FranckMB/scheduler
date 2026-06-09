<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\TeamConstraintResource;
use App\Entity\TeamConstraint;

class TeamConstraintStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return TeamConstraint::class;
    }

    protected function mapEntityToOutput(object $entity): TeamConstraintResource
    {
        return TeamConstraintResource::fromEntity($entity);
    }
}
