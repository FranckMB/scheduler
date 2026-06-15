<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SportResource;
use App\Dto\SportInput;
use App\Entity\Sport;

/**
 * @extends AbstractStateProcessor<Sport, SportInput, SportResource>
 */
class SportStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Sport::class;
    }

    /**
     * @param SportInput $input
     */
    protected function createEntityFromInput(object $input): Sport
    {
        $entity = new Sport;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->slug) {
            $entity->setSlug($input->slug);
        }
        if (null !== $input->icon) {
            $entity->setIcon($input->icon);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }

        return $entity;
    }

    /**
     * @param Sport      $entity
     * @param SportInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->slug) {
            $entity->setSlug($input->slug);
        }
        if (null !== $input->icon) {
            $entity->setIcon($input->icon);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
    }

    /**
     * @param Sport $entity
     */
    protected function mapEntityToOutput(object $entity): SportResource
    {
        return SportResource::fromEntity($entity);
    }
}
