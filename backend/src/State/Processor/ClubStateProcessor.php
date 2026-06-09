<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ClubResource;
use App\Dto\ClubInput;
use App\Entity\Club;

/**
 * @extends AbstractStateProcessor<Club, ClubInput, ClubResource>
 */
class ClubStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Club::class;
    }

    /**
     * @param ClubInput $input
     */
    protected function createEntityFromInput(object $input): Club
    {
        $entity = new Club();
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->slug) {
            $entity->setSlug($input->slug);
        }
        if (null !== $input->planId) {
            $entity->setPlanId($input->planId);
        }
        if (null !== $input->billingCycle) {
            $entity->setBillingCycle($input->billingCycle);
        }
        if (null !== $input->planExpiresAt) {
            $entity->setPlanExpiresAt($input->planExpiresAt);
        }
        if (null !== $input->generationCountSeason) {
            $entity->setGenerationCountSeason($input->generationCountSeason);
        }
        if (null !== $input->schoolZone) {
            $entity->setSchoolZone($input->schoolZone);
        }
        if (null !== $input->timezone) {
            $entity->setTimezone($input->timezone);
        }
        if (null !== $input->locale) {
            $entity->setLocale($input->locale);
        }
        if (null !== $input->onboardingCompleted) {
            $entity->setOnboardingCompleted($input->onboardingCompleted);
        }
        if (null !== $input->ffbbClubCode) {
            $entity->setFfbbClubCode($input->ffbbClubCode);
        }

        return $entity;
    }

    /**
     * @param Club      $entity
     * @param ClubInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->slug) {
            $entity->setSlug($input->slug);
        }
        if (null !== $input->planId) {
            $entity->setPlanId($input->planId);
        }
        if (null !== $input->billingCycle) {
            $entity->setBillingCycle($input->billingCycle);
        }
        if (null !== $input->planExpiresAt) {
            $entity->setPlanExpiresAt($input->planExpiresAt);
        }
        if (null !== $input->generationCountSeason) {
            $entity->setGenerationCountSeason($input->generationCountSeason);
        }
        if (null !== $input->schoolZone) {
            $entity->setSchoolZone($input->schoolZone);
        }
        if (null !== $input->timezone) {
            $entity->setTimezone($input->timezone);
        }
        if (null !== $input->locale) {
            $entity->setLocale($input->locale);
        }
        if (null !== $input->onboardingCompleted) {
            $entity->setOnboardingCompleted($input->onboardingCompleted);
        }
        if (null !== $input->ffbbClubCode) {
            $entity->setFfbbClubCode($input->ffbbClubCode);
        }
    }

    /**
     * @param Club $entity
     */
    protected function mapEntityToOutput(object $entity): ClubResource
    {
        return ClubResource::fromEntity($entity);
    }
}
