<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleSlotTemplateResource;
use App\Dto\ScheduleSlotTemplateInput;
use App\Entity\ScheduleSlotTemplate;

/**
 * @extends AbstractStateProcessor<ScheduleSlotTemplate, ScheduleSlotTemplateInput, ScheduleSlotTemplateResource>
 */
class ScheduleSlotTemplateStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return ScheduleSlotTemplate::class;
    }

    /**
     * @param ScheduleSlotTemplateInput $input
     */
    protected function createEntityFromInput(object $input): ScheduleSlotTemplate
    {
        $entity = new ScheduleSlotTemplate();
        if (null !== $input->scheduleId) {
            $entity->setScheduleId($input->scheduleId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        $entity->setStartTime($input->startTime);
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->lockLevel) {
            $entity->setLockLevel($input->lockLevel);
        }
        if (null !== $input->temporaryLock) {
            $entity->setTemporaryLock($input->temporaryLock);
        }
        if (null !== $input->temporaryLockFor) {
            $entity->setTemporaryLockFor($input->temporaryLockFor);
        }
        if (null !== $input->temporaryMinSessionsOverride) {
            $entity->setTemporaryMinSessionsOverride($input->temporaryMinSessionsOverride);
        }
        if (null !== $input->pendingConstraintSuggestion) {
            $entity->setPendingConstraintSuggestion($input->pendingConstraintSuggestion);
        }

        return $entity;
    }

    /**
     * @param ScheduleSlotTemplate      $entity
     * @param ScheduleSlotTemplateInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->scheduleId) {
            $entity->setScheduleId($input->scheduleId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        $entity->setStartTime($input->startTime);
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->lockLevel) {
            $entity->setLockLevel($input->lockLevel);
        }
        if (null !== $input->temporaryLock) {
            $entity->setTemporaryLock($input->temporaryLock);
        }
        if (null !== $input->temporaryLockFor) {
            $entity->setTemporaryLockFor($input->temporaryLockFor);
        }
        if (null !== $input->temporaryMinSessionsOverride) {
            $entity->setTemporaryMinSessionsOverride($input->temporaryMinSessionsOverride);
        }
        if (null !== $input->pendingConstraintSuggestion) {
            $entity->setPendingConstraintSuggestion($input->pendingConstraintSuggestion);
        }
    }

    /**
     * @param ScheduleSlotTemplate $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleSlotTemplateResource
    {
        return ScheduleSlotTemplateResource::fromEntity($entity);
    }
}
