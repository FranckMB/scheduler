<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ScheduleDiagnosticResource;
use App\Entity\ScheduleDiagnostic;

class ScheduleDiagnosticStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ScheduleDiagnostic::class;
    }

    protected function mapEntityToOutput(object $entity): ScheduleDiagnosticResource
    {
        return ScheduleDiagnosticResource::fromEntity($entity);
    }
}
