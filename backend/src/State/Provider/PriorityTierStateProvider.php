<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\PriorityTierResource;
use App\Entity\PriorityTier;

/**
 * @extends AbstractStateProvider<PriorityTier, PriorityTierResource>
 */
class PriorityTierStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return PriorityTier::class;
    }

    /**
     * @param PriorityTier $entity
     */
    protected function mapEntityToOutput(object $entity): PriorityTierResource
    {
        return PriorityTierResource::fromEntity($entity);
    }
}
