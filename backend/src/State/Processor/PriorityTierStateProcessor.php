<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\PriorityTierResource;
use App\Dto\PriorityTierInput;
use App\Entity\PriorityTier;

/**
 * @extends AbstractStateProcessor<PriorityTier, PriorityTierInput, PriorityTierResource>
 */
class PriorityTierStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return PriorityTier::class;
    }

    /**
     * @param PriorityTierInput $input
     */
    protected function createEntityFromInput(object $input): PriorityTier
    {
        $entity = new PriorityTier;
        if (null !== $input->label) {
            $entity->setLabel($input->label);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->orToolsWeight) {
            $entity->setOrToolsWeight($input->orToolsWeight);
        }
        if (null !== $input->defaultMinSessions) {
            $entity->setDefaultMinSessions($input->defaultMinSessions);
        }

        return $entity;
    }

    /**
     * @param PriorityTier      $entity
     * @param PriorityTierInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->label) {
            $entity->setLabel($input->label);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->orToolsWeight) {
            $entity->setOrToolsWeight($input->orToolsWeight);
        }
        if (null !== $input->defaultMinSessions) {
            $entity->setDefaultMinSessions($input->defaultMinSessions);
        }
    }

    /**
     * @param PriorityTier $entity
     */
    protected function mapEntityToOutput(object $entity): PriorityTierResource
    {
        return PriorityTierResource::fromEntity($entity);
    }
}
