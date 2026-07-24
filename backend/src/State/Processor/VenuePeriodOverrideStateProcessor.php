<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenuePeriodOverrideResource;
use App\Dto\VenuePeriodOverrideInput;
use App\Entity\VenuePeriodOverride;
use App\Enum\VenuePeriodMode;

/**
 * DÉSACTIVER UN GYMNASE NE DÉTRUIT RIEN (décision fondateur 2026-07-24) : « tout est lié
 * au gymnase, donc son indisponibilité les impacte forcément » — ses créneaux, ses
 * réservations et ses contraintes sont IGNORÉS pour la période, jamais supprimés. Le
 * réglage reste donc un pur filtre de LECTURE (ScheduleConstraintBuilder::buildForOverlay),
 * et la bascule est réversible : revenir à « hériter » (DELETE de la ligne) rend tout tel
 * quel. Une purge, elle, ferait re-saisir des créneaux prêtés perdus sur un simple clic.
 *
 * @extends AbstractStateProcessor<VenuePeriodOverride, VenuePeriodOverrideInput, VenuePeriodOverrideResource>
 */
class VenuePeriodOverrideStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenuePeriodOverride::class;
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
}
