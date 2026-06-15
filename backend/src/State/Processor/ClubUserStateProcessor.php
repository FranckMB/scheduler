<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ClubUserResource;
use App\Dto\ClubUserInput;
use App\Entity\ClubUser;

/**
 * @extends AbstractStateProcessor<ClubUser, ClubUserInput, ClubUserResource>
 */
class ClubUserStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return ClubUser::class;
    }

    /**
     * @param ClubUserInput $input
     */
    protected function createEntityFromInput(object $input): ClubUser
    {
        $entity = new ClubUser;
        if (null !== $input->userId) {
            $entity->setUserId($input->userId);
        }
        if (null !== $input->role) {
            $entity->setRole($input->role);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }

        return $entity;
    }

    /**
     * @param ClubUser      $entity
     * @param ClubUserInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->userId) {
            $entity->setUserId($input->userId);
        }
        if (null !== $input->role) {
            $entity->setRole($input->role);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
    }

    /**
     * @param ClubUser $entity
     */
    protected function mapEntityToOutput(object $entity): ClubUserResource
    {
        return ClubUserResource::fromEntity($entity);
    }
}
