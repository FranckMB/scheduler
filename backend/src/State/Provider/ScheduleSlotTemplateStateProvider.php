<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleSlotTemplateResource;
use App\Entity\ScheduleSlotTemplate;

/**
 * @extends AbstractStateProvider<ScheduleSlotTemplate, ScheduleSlotTemplateResource>
 */
class ScheduleSlotTemplateStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ScheduleSlotTemplate::class;
    }

    /**
     * @param ScheduleSlotTemplate $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleSlotTemplateResource
    {
        return ScheduleSlotTemplateResource::fromEntity($entity);
    }
}
