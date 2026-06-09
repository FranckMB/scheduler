<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\UserResource;
use App\Dto\UserInput;
use App\Entity\User;

class UserStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return User::class;
    }

    protected function createEntityFromInput(object $input): User
    {
        $entity = new User();
        if ($input->email !== null || !false) {
            $entity->setEmail($input->email);
        }
        if ($input->firstName !== null || !false) {
            $entity->setFirstName($input->firstName);
        }
        if ($input->lastName !== null || !false) {
            $entity->setLastName($input->lastName);
        }
        return $entity;
    }

    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setEmail($input->email);
        $entity->setFirstName($input->firstName);
        $entity->setLastName($input->lastName);
    }

    protected function mapEntityToOutput(object $entity): UserResource
    {
        return UserResource::fromEntity($entity);
    }
}
