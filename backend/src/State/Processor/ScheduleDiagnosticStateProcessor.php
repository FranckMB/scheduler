<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleDiagnosticResource;
use App\Dto\ScheduleDiagnosticInput;
use App\Entity\ScheduleDiagnostic;

/**
 * @extends AbstractStateProcessor<ScheduleDiagnostic, ScheduleDiagnosticInput, ScheduleDiagnosticResource>
 */
class ScheduleDiagnosticStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return ScheduleDiagnostic::class;
    }

    /**
     * @param ScheduleDiagnosticInput $input
     */
    protected function createEntityFromInput(object $input): ScheduleDiagnostic
    {
        $entity = new ScheduleDiagnostic();
        if (null !== $input->scheduleId) {
            $entity->setScheduleId($input->scheduleId);
        }
        if (null !== $input->type) {
            $entity->setType($input->type);
        }
        if (null !== $input->severity) {
            $entity->setSeverity($input->severity);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->message) {
            $entity->setMessage($input->message);
        }
        if (null !== $input->suggestions) {
            $entity->setSuggestions($input->suggestions);
        }

        return $entity;
    }

    /**
     * @param ScheduleDiagnostic      $entity
     * @param ScheduleDiagnosticInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->scheduleId) {
            $entity->setScheduleId($input->scheduleId);
        }
        if (null !== $input->type) {
            $entity->setType($input->type);
        }
        if (null !== $input->severity) {
            $entity->setSeverity($input->severity);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->coachId) {
            $entity->setCoachId($input->coachId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->message) {
            $entity->setMessage($input->message);
        }
        if (null !== $input->suggestions) {
            $entity->setSuggestions($input->suggestions);
        }
    }

    /**
     * @param ScheduleDiagnostic $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleDiagnosticResource
    {
        return ScheduleDiagnosticResource::fromEntity($entity);
    }
}
