<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\PriorityTierResource;
use App\Entity\PriorityTier;

class PriorityTierStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return PriorityTier::class;
    }

    protected function mapEntityToOutput(object $entity): PriorityTierResource
    {
        return PriorityTierResource::fromEntity($entity);
    }
}
