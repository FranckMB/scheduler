<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenueSlotPeriodExclusionResource;
use App\Dto\VenueSlotPeriodExclusionInput;
use App\Entity\VenueSlotPeriodExclusion;
use App\Entity\VenueTrainingSlot;

/**
 * @extends AbstractStateProcessor<VenueSlotPeriodExclusion, VenueSlotPeriodExclusionInput, VenueSlotPeriodExclusionResource>
 */
class VenueSlotPeriodExclusionStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueSlotPeriodExclusion::class;
    }

    /**
     * @param VenueSlotPeriodExclusionInput $input
     */
    protected function createEntityFromInput(object $input): VenueSlotPeriodExclusion
    {
        // Une seule exclusion par (période, créneau) — l'index unique remonterait sinon en
        // 500 sur un double-submit ; on rend un 422 propre (retirer = DELETE).
        if (null !== $input->schedulePlanId && null !== $input->venueTrainingSlotId
            && null !== $this->entityManager->getRepository(VenueSlotPeriodExclusion::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'venueTrainingSlotId' => $input->venueTrainingSlotId])) {
            throw new ValidationException('Ce créneau est déjà écarté pour cette période.');
        }

        // Écarter n'a de sens que pour un créneau SAISONNIER (schedulePlanId nul) : lui seul
        // survit à la période et doit revenir ensuite. Un créneau prêté n'appartient qu'à la
        // période — on le supprime, on ne l'exclut pas (sinon une ligne orpheline resterait).
        if (null !== $input->venueTrainingSlotId) {
            $slot = $this->entityManager->getRepository(VenueTrainingSlot::class)->find($input->venueTrainingSlotId);
            if (!$slot instanceof VenueTrainingSlot || null !== $slot->getSchedulePlanId()) {
                throw new ValidationException('Seul un créneau du planning principal peut être écarté ; un créneau prêté se supprime.');
            }
        }

        $entity = new VenueSlotPeriodExclusion;
        if (null !== $input->schedulePlanId) {
            $entity->setSchedulePlanId($input->schedulePlanId);
        }
        if (null !== $input->venueTrainingSlotId) {
            $entity->setVenueTrainingSlotId($input->venueTrainingSlotId);
        }

        return $entity;
    }

    /**
     * Pas de PUT sur cette ressource : une exclusion n'a aucun état éditable — elle existe
     * ou elle est supprimée. Implémentation vide imposée par la classe de base.
     *
     * @param VenueSlotPeriodExclusion      $entity
     * @param VenueSlotPeriodExclusionInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void {}

    /**
     * @param VenueSlotPeriodExclusion $entity
     */
    protected function mapEntityToOutput(object $entity): VenueSlotPeriodExclusionResource
    {
        return VenueSlotPeriodExclusionResource::fromEntity($entity);
    }
}
