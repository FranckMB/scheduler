<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\PriorityTierResource;
use App\Dto\PriorityTierInput;
use App\Entity\PriorityTier;

class PriorityTierStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return PriorityTier::class;
    }

    protected function createEntityFromInput(object $input): PriorityTier
    {
        $entity = new PriorityTier();
        if ($input->label !== null || !false) {
            $entity->setLabel($input->label);
        }
        if ($input->name !== null || !false) {
            $entity->setName($input->name);
        }
        if ($input->color !== null || !false) {
            $entity->setColor($input->color);
        }
        if ($input->orToolsWeight !== null || !false) {
            $entity->setOrToolsWeight($input->orToolsWeight);
        }
        if ($input->defaultMinSessions !== null || !false) {
            $entity->setDefaultMinSessions($input->defaultMinSessions);
        }
        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setLabel($input->label);
        $entity->setName($input->name);
        $entity->setColor($input->color);
        $entity->setOrToolsWeight($input->orToolsWeight);
        $entity->setDefaultMinSessions($input->defaultMinSessions);
    }

    protected function mapEntityToOutput(object $entity): PriorityTierResource
    {
        return PriorityTierResource::fromEntity($entity);
    }
}
