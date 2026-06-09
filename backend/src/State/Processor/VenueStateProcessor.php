<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueResource;
use App\Dto\VenueInput;
use App\Entity\Venue;

/**
 * @extends AbstractStateProcessor<Venue, VenueInput, VenueResource>
 */
class VenueStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Venue::class;
    }

    /**
     * @param VenueInput $input
     */
    protected function createEntityFromInput(object $input): Venue
    {
        $entity = new Venue();
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isExternal) {
            $entity->setIsExternal($input->isExternal);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->latitude) {
            $entity->setLatitude($input->latitude);
        }
        if (null !== $input->longitude) {
            $entity->setLongitude($input->longitude);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->externalRef) {
            $entity->setExternalRef($input->externalRef);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->parentVenueId) {
            $entity->setParentVenueId($input->parentVenueId);
        }

        return $entity;
    }

    /**
     * @param Venue      $entity
     * @param VenueInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isExternal) {
            $entity->setIsExternal($input->isExternal);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->latitude) {
            $entity->setLatitude($input->latitude);
        }
        if (null !== $input->longitude) {
            $entity->setLongitude($input->longitude);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->externalRef) {
            $entity->setExternalRef($input->externalRef);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->parentVenueId) {
            $entity->setParentVenueId($input->parentVenueId);
        }
    }

    /**
     * @param Venue $entity
     */
    protected function mapEntityToOutput(object $entity): VenueResource
    {
        return VenueResource::fromEntity($entity);
    }
}
