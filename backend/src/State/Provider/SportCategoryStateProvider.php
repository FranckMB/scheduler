<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SportCategoryResource;
use App\Entity\SportCategory;

/**
 * @extends AbstractStateProvider<SportCategory, SportCategoryResource>
 */
class SportCategoryStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return SportCategory::class;
    }

    /**
     * @param SportCategory $entity
     */
    protected function mapEntityToOutput(object $entity): SportCategoryResource
    {
        return SportCategoryResource::fromEntity($entity);
    }
}
