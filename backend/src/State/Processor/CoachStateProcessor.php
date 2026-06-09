<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachResource;
use App\Dto\CoachInput;
use App\Entity\Coach;

/**
 * @extends AbstractStateProcessor<Coach, CoachInput, CoachResource>
 */
class CoachStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Coach::class;
    }

    /**
     * @param CoachInput $input
     */
    protected function createEntityFromInput(object $input): Coach
    {
        $entity = new Coach();
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->phone) {
            $entity->setPhone($input->phone);
        }
        if (null !== $input->maxDaysOverride) {
            $entity->setMaxDaysOverride($input->maxDaysOverride);
        }
        if (null !== $input->maxDaysOverrideConfirmed) {
            $entity->setMaxDaysOverrideConfirmed($input->maxDaysOverrideConfirmed);
        }
        if (null !== $input->acceptableLateMinutes) {
            $entity->setAcceptableLateMinutes($input->acceptableLateMinutes);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->parentCoachId) {
            $entity->setParentCoachId($input->parentCoachId);
        }

        return $entity;
    }

    /**
     * @param Coach      $entity
     * @param CoachInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->phone) {
            $entity->setPhone($input->phone);
        }
        if (null !== $input->maxDaysOverride) {
            $entity->setMaxDaysOverride($input->maxDaysOverride);
        }
        if (null !== $input->maxDaysOverrideConfirmed) {
            $entity->setMaxDaysOverrideConfirmed($input->maxDaysOverrideConfirmed);
        }
        if (null !== $input->acceptableLateMinutes) {
            $entity->setAcceptableLateMinutes($input->acceptableLateMinutes);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->parentCoachId) {
            $entity->setParentCoachId($input->parentCoachId);
        }
    }

    /**
     * @param Coach $entity
     */
    protected function mapEntityToOutput(object $entity): CoachResource
    {
        return CoachResource::fromEntity($entity);
    }
}
