<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SubscriptionPlanResource;
use App\Entity\SubscriptionPlan;

/**
 * @extends AbstractStateProvider<SubscriptionPlan, SubscriptionPlanResource>
 */
class SubscriptionPlanStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return SubscriptionPlan::class;
    }

    /**
     * @param SubscriptionPlan $entity
     */
    protected function mapEntityToOutput(object $entity): SubscriptionPlanResource
    {
        return SubscriptionPlanResource::fromEntity($entity);
    }
}
