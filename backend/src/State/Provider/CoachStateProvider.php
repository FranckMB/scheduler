<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CoachResource;
use App\Entity\Coach;

/**
 * @extends AbstractStateProvider<Coach, CoachResource>
 */
class CoachStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Coach::class;
    }

    /**
     * @param Coach $entity
     */
    protected function mapEntityToOutput(object $entity): CoachResource
    {
        return CoachResource::fromEntity($entity);
    }
}
