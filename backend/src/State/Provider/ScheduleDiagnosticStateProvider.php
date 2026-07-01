<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleDiagnosticResource;
use App\Entity\ScheduleDiagnostic;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<ScheduleDiagnostic, ScheduleDiagnosticResource>
 */
class ScheduleDiagnosticStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ScheduleDiagnostic::class;
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
     * @param ScheduleDiagnostic $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleDiagnosticResource
    {
        return ScheduleDiagnosticResource::fromEntity($entity);
    }
}
