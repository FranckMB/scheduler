<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamCoachResource;
use App\Dto\TeamCoachInput;
use App\Entity\TeamCoach;

class TeamCoachStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamCoach::class;
    }

    protected function createEntityFromInput(object $input): TeamCoach
    {
        $entity = new TeamCoach();
        if ($input->teamId !== null || !false) {
            $entity->setTeamId($input->teamId);
        }
        if ($input->coachId !== null || !false) {
            $entity->setCoachId($input->coachId);
        }
        if ($input->role !== null || !false) {
            $entity->setRole($input->role);
        }
        if ($input->isRequired !== null || !false) {
            $entity->setIsRequired($input->isRequired);
        }
        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setTeamId($input->teamId);
        $entity->setCoachId($input->coachId);
        $entity->setRole($input->role);
        $entity->setIsRequired($input->isRequired);
    }

    protected function mapEntityToOutput(object $entity): TeamCoachResource
    {
        return TeamCoachResource::fromEntity($entity);
    }
}
