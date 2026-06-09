<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\ClubUserResource;
use App\Entity\ClubUser;

class ClubUserStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return ClubUser::class;
    }

    protected function mapEntityToOutput(object $entity): ClubUserResource
    {
        return ClubUserResource::fromEntity($entity);
    }
}
