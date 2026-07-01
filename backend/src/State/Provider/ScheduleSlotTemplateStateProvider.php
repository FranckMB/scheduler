<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleSlotTemplateResource;
use App\Entity\ScheduleSlotTemplate;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<ScheduleSlotTemplate, ScheduleSlotTemplateResource>
 */
class ScheduleSlotTemplateStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ScheduleSlotTemplate::class;
    }

    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $scheduleId = $this->requestStack->getCurrentRequest()?->query->get('scheduleId');
        if (\is_string($scheduleId) && '' !== $scheduleId) {
            $qb->andWhere('e.scheduleId = :scheduleId')->setParameter('scheduleId', $scheduleId);

            return true;
        }

        return false;
    }

    /**
     * @param ScheduleSlotTemplate $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleSlotTemplateResource
    {
        return ScheduleSlotTemplateResource::fromEntity($entity);
    }
}
