<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SeasonResource;
use App\Dto\SeasonInput;
use App\Entity\Season;

/**
 * @extends AbstractStateProcessor<Season, SeasonInput, SeasonResource>
 */
class SeasonStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Season::class;
    }

    /**
     * @param SeasonInput $input
     */
    protected function createEntityFromInput(object $input): Season
    {
        $entity = new Season;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        $entity->setStartDate($input->startDate);
        $entity->setEndDate($input->endDate);
        if (null !== $input->status) {
            $entity->setStatus($input->status);
        }
        if (null !== $input->planningName) {
            $entity->setPlanningName('' === trim($input->planningName) ? null : trim($input->planningName));
        }

        return $entity;
    }

    /**
     * @param Season      $entity
     * @param SeasonInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        $entity->setStartDate($input->startDate);
        $entity->setEndDate($input->endDate);
        if (null !== $input->status) {
            $entity->setStatus($input->status);
        }
        // Empty string = explicit reset to the default display name.
        if (null !== $input->planningName) {
            $entity->setPlanningName('' === trim($input->planningName) ? null : trim($input->planningName));
        }
    }

    /**
     * @param Season $entity
     */
    protected function mapEntityToOutput(object $entity): SeasonResource
    {
        return SeasonResource::fromEntity($entity);
    }
}
