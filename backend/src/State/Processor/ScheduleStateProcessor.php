<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ScheduleResource;
use App\Dto\ScheduleInput;
use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @extends AbstractStateProcessor<Schedule, ScheduleInput, ScheduleResource>
 */
class ScheduleStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Schedule::class;
    }

    /**
     * @param ScheduleInput $input
     */
    protected function createEntityFromInput(object $input): Schedule
    {
        $entity = new Schedule;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->status) {
            $status = ScheduleStatus::tryFrom($input->status);
            if (null !== $status) {
                $entity->setStatus($status);
            }
        }
        if (null !== $input->solverSeed) {
            $entity->setSolverSeed($input->solverSeed);
        }

        return $entity;
    }

    /**
     * @param Schedule      $entity
     * @param ScheduleInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // A validated schedule is read-only: reopen it (POST /reopen) before editing.
        if (ScheduleStatus::VALIDATED === $entity->getStatus()) {
            throw new ConflictHttpException('This schedule is validated (read-only). Reopen it before editing.');
        }
        // Status transitions go through the dedicated endpoints (generate/validate/reopen),
        // never a free-form PUT.
        if ('VALIDATED' === $input->status) {
            throw new ConflictHttpException('Use POST /schedules/{id}/validate to validate a schedule.');
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->status) {
            $status = ScheduleStatus::tryFrom($input->status);
            if (null !== $status) {
                $entity->setStatus($status);
            }
        }
        if (null !== $input->solverSeed) {
            $entity->setSolverSeed($input->solverSeed);
        }
    }

    /**
     * @param Schedule $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleResource
    {
        return ScheduleResource::fromEntity($entity);
    }
}
