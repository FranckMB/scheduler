<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SportCategoryResource;
use App\Dto\SportCategoryInput;
use App\Entity\SportCategory;

/**
 * @extends AbstractStateProcessor<SportCategory, SportCategoryInput, SportCategoryResource>
 */
class SportCategoryStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return SportCategory::class;
    }

    /**
     * @param SportCategoryInput $input
     */
    protected function createEntityFromInput(object $input): SportCategory
    {
        $entity = new SportCategory();
        if (null !== $input->sportId) {
            $entity->setSportId($input->sportId);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isCustom) {
            $entity->setIsCustom($input->isCustom);
        }
        if (null !== $input->ageMin) {
            $entity->setAgeMin($input->ageMin);
        }
        if (null !== $input->ageMax) {
            $entity->setAgeMax($input->ageMax);
        }
        if (null !== $input->sortOrder) {
            $entity->setSortOrder($input->sortOrder);
        }

        return $entity;
    }

    /**
     * @param SportCategory      $entity
     * @param SportCategoryInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->sportId) {
            $entity->setSportId($input->sportId);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isCustom) {
            $entity->setIsCustom($input->isCustom);
        }
        if (null !== $input->ageMin) {
            $entity->setAgeMin($input->ageMin);
        }
        if (null !== $input->ageMax) {
            $entity->setAgeMax($input->ageMax);
        }
        if (null !== $input->sortOrder) {
            $entity->setSortOrder($input->sortOrder);
        }
    }

    /**
     * @param SportCategory $entity
     */
    protected function mapEntityToOutput(object $entity): SportCategoryResource
    {
        return SportCategoryResource::fromEntity($entity);
    }
}
