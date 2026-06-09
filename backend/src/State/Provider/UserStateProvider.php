<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\UserResource;
use App\Entity\User;

/**
 * @extends AbstractStateProvider<User, UserResource>
 */
class UserStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return User::class;
    }

    /**
     * @param User $entity
     */
    protected function mapEntityToOutput(object $entity): UserResource
    {
        return UserResource::fromEntity($entity);
    }
}
