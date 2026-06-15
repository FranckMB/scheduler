<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\UserResource;
use App\Dto\UserInput;
use App\Entity\User;

/**
 * @extends AbstractStateProcessor<User, UserInput, UserResource>
 */
class UserStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return User::class;
    }

    /**
     * @param UserInput $input
     */
    protected function createEntityFromInput(object $input): User
    {
        $entity = new User;
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }

        return $entity;
    }

    /**
     * @param User      $entity
     * @param UserInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->email) {
            $entity->setEmail($input->email);
        }
        if (null !== $input->firstName) {
            $entity->setFirstName($input->firstName);
        }
        if (null !== $input->lastName) {
            $entity->setLastName($input->lastName);
        }
    }

    /**
     * @param User $entity
     */
    protected function mapEntityToOutput(object $entity): UserResource
    {
        return UserResource::fromEntity($entity);
    }
}
