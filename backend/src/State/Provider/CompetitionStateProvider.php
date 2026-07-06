<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CompetitionResource;
use App\Entity\Competition;

/**
 * @extends AbstractStateProvider<Competition, CompetitionResource>
 */
class CompetitionStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /**
     * @param Competition $entity
     */
    protected function mapEntityToOutput(object $entity): CompetitionResource
    {
        return CompetitionResource::fromEntity($entity);
    }
}
