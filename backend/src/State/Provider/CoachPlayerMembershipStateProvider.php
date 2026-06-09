<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CoachPlayerMembershipResource;
use App\Entity\CoachPlayerMembership;

/**
 * @extends AbstractStateProvider<CoachPlayerMembership, CoachPlayerMembershipResource>
 */
class CoachPlayerMembershipStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return CoachPlayerMembership::class;
    }

    /**
     * @param CoachPlayerMembership $entity
     */
    protected function mapEntityToOutput(object $entity): CoachPlayerMembershipResource
    {
        return CoachPlayerMembershipResource::fromEntity($entity);
    }
}
