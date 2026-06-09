<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleDiagnosticResource;
use App\Entity\ScheduleDiagnostic;

/**
 * @extends AbstractStateProvider<ScheduleDiagnostic, ScheduleDiagnosticResource>
 */
class ScheduleDiagnosticStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ScheduleDiagnostic::class;
    }

    /**
     * @param ScheduleDiagnostic $entity
     */
    protected function mapEntityToOutput(object $entity): ScheduleDiagnosticResource
    {
        return ScheduleDiagnosticResource::fromEntity($entity);
    }
}
