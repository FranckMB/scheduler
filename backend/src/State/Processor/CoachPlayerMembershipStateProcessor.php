<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachPlayerMembershipResource;
use App\Dto\CoachPlayerMembershipInput;
use App\Entity\CoachPlayerMembership;

/**
 * @extends AbstractStateProcessor<CoachPlayerMembership, CoachPlayerMembershipInput, CoachPlayerMembershipResource>
 */
class CoachPlayerMembershipStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CoachPlayerMembership::class;
    }

    /**
     * @param CoachPlayerMembershipInput $input
     */
    protected function createEntityFromInput(object $input): CoachPlayerMembership
    {
        $entity = new CoachPlayerMembership;
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->position) {
            $entity->setPosition($input->position);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }

        return $entity;
    }

    /**
     * @param CoachPlayerMembership      $entity
     * @param CoachPlayerMembershipInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->position) {
            $entity->setPosition($input->position);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
    }

    /**
     * @param CoachPlayerMembership $entity
     */
    protected function mapEntityToOutput(object $entity): CoachPlayerMembershipResource
    {
        return CoachPlayerMembershipResource::fromEntity($entity);
    }
}
