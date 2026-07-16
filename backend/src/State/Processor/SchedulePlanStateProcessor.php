<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\SchedulePlanResource;
use App\Dto\SchedulePlanInput;
use App\Entity\SchedulePlan;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Renommer un plan — la SEULE écriture client sur un plan, et le SEUL écrivain du
 * nom (ADR-0002 inv. 12 : le nom public vit sur le plan).
 *
 * Un plan naît par provisioning et meurt avec son déclencheur ; sa version choisie
 * se désigne en validant cette version. Le client ne peut donc ni le créer, ni le
 * supprimer, ni toucher au pointeur.
 *
 * @extends AbstractStateProcessor<SchedulePlan, SchedulePlanInput, SchedulePlanResource>
 */
class SchedulePlanStateProcessor extends AbstractStateProcessor
{
    /** SEC-07 : renommer le planning est une écriture cockpit (management). */
    protected function requiresManagementRole(): bool
    {
        return true;
    }

    protected function getEntityClass(): string
    {
        return SchedulePlan::class;
    }

    /**
     * @param SchedulePlanInput $input
     */
    protected function createEntityFromInput(object $input): SchedulePlan
    {
        // Injoignable (la ressource n'expose pas Post) — explicite plutôt que muet.
        throw new MethodNotAllowedHttpException(['GET', 'PUT'], 'Un planning est créé par le système, pas via l\'API.');
    }

    /**
     * @param SchedulePlan      $entity
     * @param SchedulePlanInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // `name` UNIQUEMENT.
        $entity->setName(trim($input->name));
    }

    /**
     * @param SchedulePlan $entity
     */
    protected function mapEntityToOutput(object $entity): SchedulePlanResource
    {
        return SchedulePlanResource::fromEntity($entity);
    }
}
