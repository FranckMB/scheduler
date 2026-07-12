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
        if (null === $input->startDate || null === $input->endDate) {
            throw new \ApiPlatform\Validator\Exception\ValidationException('startDate and endDate are required to create a season.');
        }
        $entity->setStartDate($input->startDate);
        $entity->setEndDate($input->endDate);
        if (null !== $input->status) {
            $entity->setStatus($input->status);
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
        // Partial PUT: absent dates keep the current values — a client updating
        // one field must not echo (possibly stale) dates.
        if (null !== $input->startDate) {
            $entity->setStartDate($input->startDate);
        }
        if (null !== $input->endDate) {
            $entity->setEndDate($input->endDate);
        }
        if (null !== $input->status) {
            $entity->setStatus($input->status);
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
