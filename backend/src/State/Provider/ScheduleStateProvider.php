<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleResource;
use App\Entity\Schedule;

class ScheduleStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Schedule::class;
    }

    protected function mapEntityToOutput(object $entity): ScheduleResource
    {
        return ScheduleResource::fromEntity($entity);
    }
}
