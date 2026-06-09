<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ClubUserResource;
use App\Entity\ClubUser;

/**
 * @extends AbstractStateProvider<ClubUser, ClubUserResource>
 */
class ClubUserStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ClubUser::class;
    }

    /**
     * @param ClubUser $entity
     */
    protected function mapEntityToOutput(object $entity): ClubUserResource
    {
        return ClubUserResource::fromEntity($entity);
    }
}
