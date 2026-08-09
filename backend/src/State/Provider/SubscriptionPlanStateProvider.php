<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\ApiResource\SubscriptionPlanResource;
use App\Entity\SubscriptionPlan;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends AbstractStateProvider<SubscriptionPlan, SubscriptionPlanResource>
 */
class SubscriptionPlanStateProvider extends AbstractStateProvider
{
    protected function getEntityClass(): string
    {
        return SubscriptionPlan::class;
    }

    // La bêta est une offre superadmin-only : jamais dans le catalogue public.
    // (Le GET item par id n'est pas masqué — la spec ne borne que la collection.)
    protected function applyRequestFilters(QueryBuilder $qb): bool
    {
        $qb->andWhere('e.code != :betaCode')->setParameter('betaCode', 'beta');

        return false;
    }

    /**
     * @param SubscriptionPlan $entity
     */
    protected function mapEntityToOutput(object $entity): SubscriptionPlanResource
    {
        return SubscriptionPlanResource::fromEntity($entity);
    }
}
