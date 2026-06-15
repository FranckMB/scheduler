<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueConstraintResource;
use App\Dto\VenueConstraintInput;
use App\Entity\VenueConstraint;

/**
 * @extends AbstractStateProcessor<VenueConstraint, VenueConstraintInput, VenueConstraintResource>
 */
class VenueConstraintStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueConstraint::class;
    }

    /**
     * @param VenueConstraintInput $input
     */
    protected function createEntityFromInput(object $input): VenueConstraint
    {
        $entity = new VenueConstraint();
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->constraintType) {
            $entity->setConstraintType($input->constraintType);
        }
        if (null !== $input->constraintValue) {
            $entity->setConstraintValue($input->constraintValue);
        }

        return $entity;
    }

    /**
     * @param VenueConstraint      $entity
     * @param VenueConstraintInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->constraintType) {
            $entity->setConstraintType($input->constraintType);
        }
        if (null !== $input->constraintValue) {
            $entity->setConstraintValue($input->constraintValue);
        }
    }

    /**
     * @param VenueConstraint $entity
     */
    protected function mapEntityToOutput(object $entity): VenueConstraintResource
    {
        return VenueConstraintResource::fromEntity($entity);
    }
}
