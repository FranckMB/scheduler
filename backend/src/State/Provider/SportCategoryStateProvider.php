<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SportCategoryResource;
use App\Entity\SportCategory;

class SportCategoryStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return SportCategory::class;
    }

    protected function mapEntityToOutput(object $entity): SportCategoryResource
    {
        return SportCategoryResource::fromEntity($entity);
    }
}
