<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SportResource;
use App\Entity\Sport;

/**
 * @extends AbstractStateProvider<Sport, SportResource>
 */
class SportStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Sport::class;
    }

    /**
     * @param Sport $entity
     */
    protected function mapEntityToOutput(object $entity): SportResource
    {
        return SportResource::fromEntity($entity);
    }
}
