<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\ConstraintPeriodOverrideResource;
use App\Dto\ConstraintPeriodOverrideInput;
use App\Entity\ConstraintPeriodOverride;

/**
 * @extends AbstractStateProcessor<ConstraintPeriodOverride, ConstraintPeriodOverrideInput, ConstraintPeriodOverrideResource>
 */
class ConstraintPeriodOverrideStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return ConstraintPeriodOverride::class;
    }

    /**
     * @param ConstraintPeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): ConstraintPeriodOverride
    {
        // One override per (period, constraint) — the DB unique index would otherwise
        // surface as a 500 on a double-submit; give a clean 422 instead (edit via PUT).
        if (null !== $input->calendarEntryId && null !== $input->constraintId
            && null !== $this->entityManager->getRepository(ConstraintPeriodOverride::class)->findOneBy(['calendarEntryId' => $input->calendarEntryId, 'constraintId' => $input->constraintId])) {
            throw new ValidationException('This constraint already has an override for this period — edit it instead.');
        }

        $entity = new ConstraintPeriodOverride;
        if (null !== $input->calendarEntryId) {
            $entity->setCalendarEntryId($input->calendarEntryId);
        }
        if (null !== $input->constraintId) {
            $entity->setConstraintId($input->constraintId);
        }
        $entity->setIsActive($input->isActive);

        return $entity;
    }

    /**
     * @param ConstraintPeriodOverride      $entity
     * @param ConstraintPeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // calendarEntryId + constraintId identify the row — not remapped on edit.
        $entity->setIsActive($input->isActive);
    }

    /**
     * @param ConstraintPeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): ConstraintPeriodOverrideResource
    {
        return ConstraintPeriodOverrideResource::fromEntity($entity);
    }
}
