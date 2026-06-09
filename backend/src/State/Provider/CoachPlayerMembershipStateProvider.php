<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\CoachPlayerMembershipResource;
use App\Entity\CoachPlayerMembership;

class CoachPlayerMembershipStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return CoachPlayerMembership::class;
    }

    protected function mapEntityToOutput(object $entity): CoachPlayerMembershipResource
    {
        return CoachPlayerMembershipResource::fromEntity($entity);
    }
}
