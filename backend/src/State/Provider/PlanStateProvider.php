<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\PlanResource;
use App\Entity\Plan;

class PlanStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Plan::class;
    }

    protected function mapEntityToOutput(object $entity): PlanResource
    {
        return PlanResource::fromEntity($entity);
    }
}
