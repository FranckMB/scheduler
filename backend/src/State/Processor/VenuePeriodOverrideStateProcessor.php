<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenuePeriodOverrideResource;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\Reservation;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueSlotPeriodExclusion;
use App\Entity\VenueTrainingSlot;
use App\Enum\VenuePeriodMode;

/**
 * @extends AbstractStateProcessor<VenuePeriodOverride, VenuePeriodOverrideInput, VenuePeriodOverrideResource>
 */
class VenuePeriodOverrideStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenuePeriodOverride::class;
    }

    /**
     * Passer un gymnase en DÉSACTIVÉ le vide de tout ce qui n'aurait plus de sens pour
     * cette période : ses créneaux PRÊTÉS, ses réservations, et les créneaux de saison
     * qu'on avait écartés (le gymnase entier l'est désormais). L'UI confirme AVANT — mais
     * la cohérence est garantie ICI, sinon un appel direct à l'API laisserait des lignes
     * qui pointent un gymnase absent du payload (cf. filtres défensifs de
     * ScheduleConstraintBuilder::buildForOverlay). ATOMIQUE avec l'écriture du mode : un
     * mode DISABLED commité sans sa purge laisserait exactement ces orphelins.
     *
     * @param VenuePeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $output = parent::processPost($input, $clubId, $seasonId);
            $this->purgeIfDisabled($output);

            return $output;
        });
    }

    /**
     * @param array<string, mixed>     $uriVariables
     * @param VenuePeriodOverrideInput $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $uriVariables, $clubId, $seasonId): object {
            $output = parent::processPut($input, $uriVariables, $clubId, $seasonId);
            // La bascule vers DISABLED peut aussi venir d'une ÉDITION (hériter → désactivé) :
            // la purge doit s'appliquer aux deux verbes, sinon le chemin PUT laisse passer.
            $this->purgeIfDisabled($output);

            return $output;
        });
    }

    /**
     * @param VenuePeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): VenuePeriodOverride
    {
        // Un seul réglage par (période, gymnase) — l'index unique remonterait sinon en 500
        // sur un double-submit ; on rend un 422 propre (l'édition passe par PUT).
        if (null !== $input->schedulePlanId && null !== $input->venueId
            && null !== $this->entityManager->getRepository(VenuePeriodOverride::class)->findOneBy(['schedulePlanId' => $input->schedulePlanId, 'venueId' => $input->venueId])) {
            throw new ValidationException('Ce gymnase a déjà un réglage pour cette période — modifiez-le.');
        }

        $entity = new VenuePeriodOverride;
        if (null !== $input->schedulePlanId) {
            $entity->setSchedulePlanId($input->schedulePlanId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->mode) {
            $entity->setMode(VenuePeriodMode::from($input->mode));
        }

        return $entity;
    }

    /**
     * @param VenuePeriodOverride      $entity
     * @param VenuePeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // schedulePlanId + venueId identifient la ligne — jamais remappés à l'édition.
        if (null !== $input->mode) {
            $entity->setMode(VenuePeriodMode::from($input->mode));
        }
    }

    /**
     * @param VenuePeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): VenuePeriodOverrideResource
    {
        return VenuePeriodOverrideResource::fromEntity($entity);
    }

    /**
     * Purge des lignes de période devenues sans objet.
     *
     * L'ancre vient de la RESSOURCE PERSISTÉE, jamais du corps de la requête : au PUT,
     * `updateEntityFromInput` ignore délibérément schedulePlanId/venueId (ils identifient
     * la ligne), donc un corps qui les change ne déplace PAS la ligne — s'y fier ferait
     * purger les créneaux et réservations d'une AUTRE période/gymnase que celle éditée.
     */
    private function purgeIfDisabled(VenuePeriodOverrideResource $output): void
    {
        if (VenuePeriodMode::DISABLED->value !== $output->mode) {
            return;
        }
        $schedulePlanId = $output->schedulePlanId;
        $venueId = $output->venueId;

        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $schedulePlanId, 'venueId' => $venueId]) as $slot) {
            $this->entityManager->remove($slot);
        }
        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId, 'venueId' => $venueId]) as $reservation) {
            $this->entityManager->remove($reservation);
        }
        // Les exclusions ne portent que l'id du créneau : on résout d'abord les créneaux de
        // SAISON de ce gymnase, puis on retire les exclusions qui les visaient.
        $seasonalSlotIds = array_map(
            static fn (VenueTrainingSlot $slot): string => $slot->getId(),
            $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['venueId' => $venueId, 'schedulePlanId' => null]),
        );
        if ([] !== $seasonalSlotIds) {
            foreach ($this->entityManager->getRepository(VenueSlotPeriodExclusion::class)->findBy(['schedulePlanId' => $schedulePlanId, 'venueTrainingSlotId' => $seasonalSlotIds]) as $exclusion) {
                $this->entityManager->remove($exclusion);
            }
        }
        $this->entityManager->flush();
    }
}
