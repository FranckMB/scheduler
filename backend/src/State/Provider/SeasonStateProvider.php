<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SeasonResource;
use App\Entity\Season;

class SeasonStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Season::class;
    }

    protected function mapEntityToOutput(object $entity): SeasonResource
    {
        return SeasonResource::fromEntity($entity);
    }
}
