<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ClubResource;
use App\Entity\Club;

/**
 * @extends AbstractStateProvider<Club, ClubResource>
 */
class ClubStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Club::class;
    }

    /**
     * @param Club $entity
     */
    protected function mapEntityToOutput(object $entity): ClubResource
    {
        return ClubResource::fromEntity($entity);
    }
}
