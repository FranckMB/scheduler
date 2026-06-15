<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\PlanResource;
use App\Dto\PlanInput;
use App\Entity\Plan;

/**
 * @extends AbstractStateProcessor<Plan, PlanInput, PlanResource>
 */
class PlanStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Plan::class;
    }

    /**
     * @param PlanInput $input
     */
    protected function createEntityFromInput(object $input): Plan
    {
        $entity = new Plan;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->maxTeams) {
            $entity->setMaxTeams($input->maxTeams);
        }
        if (null !== $input->maxVenues) {
            $entity->setMaxVenues($input->maxVenues);
        }
        if (null !== $input->maxGenerations) {
            $entity->setMaxGenerations($input->maxGenerations);
        }
        if (null !== $input->monthlyPrice) {
            $entity->setMonthlyPrice($input->monthlyPrice);
        }
        if (null !== $input->annualPrice) {
            $entity->setAnnualPrice($input->annualPrice);
        }
        if (null !== $input->features) {
            $entity->setFeatures($input->features);
        }

        return $entity;
    }

    /**
     * @param Plan      $entity
     * @param PlanInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->maxTeams) {
            $entity->setMaxTeams($input->maxTeams);
        }
        if (null !== $input->maxVenues) {
            $entity->setMaxVenues($input->maxVenues);
        }
        if (null !== $input->maxGenerations) {
            $entity->setMaxGenerations($input->maxGenerations);
        }
        if (null !== $input->monthlyPrice) {
            $entity->setMonthlyPrice($input->monthlyPrice);
        }
        if (null !== $input->annualPrice) {
            $entity->setAnnualPrice($input->annualPrice);
        }
        if (null !== $input->features) {
            $entity->setFeatures($input->features);
        }
    }

    /**
     * @param Plan $entity
     */
    protected function mapEntityToOutput(object $entity): PlanResource
    {
        return PlanResource::fromEntity($entity);
    }
}
