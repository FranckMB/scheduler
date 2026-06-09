<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueClosureResource;
use App\Dto\VenueClosureInput;
use App\Entity\VenueClosure;

/**
 * @extends AbstractStateProcessor<VenueClosure, VenueClosureInput, VenueClosureResource>
 */
class VenueClosureStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueClosure::class;
    }

    /**
     * @param VenueClosureInput $input
     */
    protected function createEntityFromInput(object $input): VenueClosure
    {
        $entity = new VenueClosure();
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        $entity->setDateStart($input->dateStart);
        $entity->setDateEnd($input->dateEnd);
        if (null !== $input->reason) {
            $entity->setReason($input->reason);
        }

        return $entity;
    }

    /**
     * @param VenueClosure      $entity
     * @param VenueClosureInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        $entity->setDateStart($input->dateStart);
        $entity->setDateEnd($input->dateEnd);
        if (null !== $input->reason) {
            $entity->setReason($input->reason);
        }
    }

    /**
     * @param VenueClosure $entity
     */
    protected function mapEntityToOutput(object $entity): VenueClosureResource
    {
        return VenueClosureResource::fromEntity($entity);
    }
}
