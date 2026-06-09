<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachResource;
use App\Dto\CoachInput;
use App\Entity\Coach;

class CoachStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Coach::class;
    }

    protected function createEntityFromInput(object $input): Coach
    {
        $entity = new Coach();
        if ($input->firstName !== null || !false) {
            $entity->setFirstName($input->firstName);
        }
        if ($input->lastName !== null || !false) {
            $entity->setLastName($input->lastName);
        }
        if ($input->email !== null || !true) {
            $entity->setEmail($input->email);
        }
        if ($input->phone !== null || !true) {
            $entity->setPhone($input->phone);
        }
        if ($input->maxDaysOverride !== null || !true) {
            $entity->setMaxDaysOverride($input->maxDaysOverride);
        }
        if ($input->maxDaysOverrideConfirmed !== null || !false) {
            $entity->setMaxDaysOverrideConfirmed($input->maxDaysOverrideConfirmed);
        }
        if ($input->acceptableLateMinutes !== null || !true) {
            $entity->setAcceptableLateMinutes($input->acceptableLateMinutes);
        }
        if ($input->isActive !== null || !false) {
            $entity->setIsActive($input->isActive);
        }
        if ($input->parentCoachId !== null || !true) {
            $entity->setParentCoachId($input->parentCoachId);
        }
        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setFirstName($input->firstName);
        $entity->setLastName($input->lastName);
        $entity->setEmail($input->email);
        $entity->setPhone($input->phone);
        $entity->setMaxDaysOverride($input->maxDaysOverride);
        $entity->setMaxDaysOverrideConfirmed($input->maxDaysOverrideConfirmed);
        $entity->setAcceptableLateMinutes($input->acceptableLateMinutes);
        $entity->setIsActive($input->isActive);
        $entity->setParentCoachId($input->parentCoachId);
    }

    protected function mapEntityToOutput(object $entity): CoachResource
    {
        return CoachResource::fromEntity($entity);
    }
}
