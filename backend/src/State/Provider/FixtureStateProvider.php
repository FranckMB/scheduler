<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\FixtureResource;
use App\Entity\Fixture;

/**
 * @extends AbstractStateProvider<Fixture, FixtureResource>
 */
class FixtureStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return Fixture::class;
    }

    /**
     * @param Fixture $entity
     */
    protected function mapEntityToOutput(object $entity): FixtureResource
    {
        return FixtureResource::fromEntity($entity);
    }
}
