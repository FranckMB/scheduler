<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CoachResource;
use App\Entity\Coach;

class CoachStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Coach::class;
    }

    protected function mapEntityToOutput(object $entity): CoachResource
    {
        return CoachResource::fromEntity($entity);
    }
}
