<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleDiagnosticResource;
use App\Entity\ScheduleDiagnostic;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * @extends AbstractStateProvider<ScheduleDiagnostic, ScheduleDiagnosticResource>
 */
class ScheduleDiagnosticStateProvider extends AbstractStateProvider
{
    use ReadsUuidQueryParamTrait;

    protected function getEntityClass(): string
    {
        return ScheduleDiagnostic::class;
    }

    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $scheduleId = $request instanceof Request ? $this->uuidQueryParam($request, 'scheduleId') : null;
        if (null !== $scheduleId) {
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
