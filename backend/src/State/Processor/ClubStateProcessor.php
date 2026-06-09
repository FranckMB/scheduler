<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ClubResource;
use App\Dto\ClubInput;
use App\Entity\Club;

class ClubStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Club::class;
    }

    protected function createEntityFromInput(object $input): Club
    {
        $entity = new Club();
        if ($input->name !== null || !false) {
            $entity->setName($input->name);
        }
        if ($input->slug !== null || !false) {
            $entity->setSlug($input->slug);
        }
        if ($input->planId !== null || !true) {
            $entity->setPlanId($input->planId);
        }
        if ($input->billingCycle !== null || !true) {
            $entity->setBillingCycle($input->billingCycle);
        }
        if ($input->planExpiresAt !== null || !true) {
            $entity->setPlanExpiresAt($input->planExpiresAt);
        }
        if ($input->generationCountSeason !== null || !false) {
            $entity->setGenerationCountSeason($input->generationCountSeason);
        }
        if ($input->schoolZone !== null || !true) {
            $entity->setSchoolZone($input->schoolZone);
        }
        if ($input->timezone !== null || !false) {
            $entity->setTimezone($input->timezone);
        }
        if ($input->locale !== null || !false) {
            $entity->setLocale($input->locale);
        }
        if ($input->onboardingCompleted !== null || !false) {
            $entity->setOnboardingCompleted($input->onboardingCompleted);
        }
        if ($input->ffbbClubCode !== null || !true) {
            $entity->setFfbbClubCode($input->ffbbClubCode);
        }
        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setName($input->name);
        $entity->setSlug($input->slug);
        $entity->setPlanId($input->planId);
        $entity->setBillingCycle($input->billingCycle);
        $entity->setPlanExpiresAt($input->planExpiresAt);
        $entity->setGenerationCountSeason($input->generationCountSeason);
        $entity->setSchoolZone($input->schoolZone);
        $entity->setTimezone($input->timezone);
        $entity->setLocale($input->locale);
        $entity->setOnboardingCompleted($input->onboardingCompleted);
        $entity->setFfbbClubCode($input->ffbbClubCode);
    }

    protected function mapEntityToOutput(object $entity): ClubResource
    {
        return ClubResource::fromEntity($entity);
    }
}
