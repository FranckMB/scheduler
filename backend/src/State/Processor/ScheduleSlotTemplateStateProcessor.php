<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleSlotTemplateResource;
use App\Dto\ScheduleSlotTemplateInput;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ScheduleStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @extends AbstractStateProcessor<ScheduleSlotTemplate, ScheduleSlotTemplateInput, ScheduleSlotTemplateResource>
 */
class ScheduleSlotTemplateStateProcessor extends AbstractStateProcessor
{
    /**
     * SEC-07: raw slot CRUD mutates the same fields the guarded manual-edit
     * routes protect (lockLevel, temporaryLock, move/create/delete a slot) —
     * without this, the guard on ManualEditController is a door next to an
     * open wall.
     */
    protected function requiresManagementRole(): bool
    {
        return true;
    }

    protected function getEntityClass(): string
    {
        return ScheduleSlotTemplate::class;
    }

    /**
     * @param ScheduleSlotTemplateInput $input
     */
    protected function createEntityFromInput(object $input): ScheduleSlotTemplate
    {
        $this->assertScheduleEditable($input->scheduleId);
        $entity = new ScheduleSlotTemplate;
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
        $this->assertScheduleEditable($entity->getScheduleId());
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

    /** @param array<string, mixed> $uriVariables */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $slot = $this->entityManager->find(ScheduleSlotTemplate::class, $uriVariables['id'] ?? null);
        if ($slot instanceof ScheduleSlotTemplate) {
            $this->assertScheduleEditable($slot->getScheduleId());
        }
        parent::processDelete($uriVariables, $clubId);
    }

    /** A slot template whose parent schedule is VALIDATED is read-only. */
    private function assertScheduleEditable(?string $scheduleId): void
    {
        if (null === $scheduleId) {
            return;
        }
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
        if ($schedule instanceof Schedule && ScheduleStatus::VALIDATED === $schedule->getStatus()) {
            throw new ConflictHttpException('This schedule is validated (read-only). Reopen it before editing.');
        }
    }
}
