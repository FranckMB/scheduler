<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\UserResource;
use App\Entity\User;

class UserStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return User::class;
    }

    protected function mapEntityToOutput(object $entity): UserResource
    {
        return UserResource::fromEntity($entity);
    }
}
