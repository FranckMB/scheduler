<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ClubResource;
use App\Entity\Club;

class ClubStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Club::class;
    }

    protected function mapEntityToOutput(object $entity): ClubResource
    {
        return ClubResource::fromEntity($entity);
    }
}
