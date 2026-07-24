<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenueTrainingSlotResource;
use App\Dto\VenueTrainingSlotInput;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueSlotPeriodExclusion;
use App\Entity\VenueTrainingSlot;
use App\Enum\VenuePeriodMode;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<VenueTrainingSlot, VenueTrainingSlotInput, VenueTrainingSlotResource>
 */
class VenueTrainingSlotStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueTrainingSlot::class;
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof VenueTrainingSlot) {
            $this->cascadeDeleter?->purgeChildrenOfSlot($entity);
        }
    }

    /**
     * @param VenueTrainingSlotInput $input
     */
    protected function createEntityFromInput(object $input): VenueTrainingSlot
    {
        $entity = new VenueTrainingSlot;
        // Period slot (schedulePlanId set) vs seasonal (null) — set only on create;
        // a slot never migrates between the seasonal and a period layer.
        $entity->setSchedulePlanId($input->schedulePlanId);
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->capacity) {
            $entity->setCapacity($input->capacity);
        }

        $this->validateCapacityForVenue($entity);
        $this->validateNoOverlap($entity);

        return $entity;
    }

    /**
     * @param VenueTrainingSlot      $entity
     * @param VenueTrainingSlotInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        if (null !== $input->capacity) {
            $entity->setCapacity($input->capacity);
        }

        $this->validateCapacityForVenue($entity);
        $this->validateNoOverlap($entity);
    }

    /**
     * @param VenueTrainingSlot $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTrainingSlotResource
    {
        return VenueTrainingSlotResource::fromEntity($entity);
    }

    private function validateCapacityForVenue(VenueTrainingSlot $entity): void
    {
        if ($entity->getCapacity() <= 1) {
            return;
        }

        $venue = $this->entityManager->find(Venue::class, $entity->getVenueId());

        if ($venue instanceof Venue && false === $venue->getCanSplit()) {
            throw new ValidationException('Cannot set capacity > 1 on a venue that cannot split.');
        }
    }

    /**
     * Two slots of the SAME gym may never share any time on the same weekday —
     * divisibility is a within-slot capacity, not a licence to stack slots. The
     * wizard guards this client-side too; this is the server backstop so no
     * client can ever persist overlapping availability.
     */
    private function validateNoOverlap(VenueTrainingSlot $entity): void
    {
        $start = $this->minutesOf($entity);
        $end = $start + $entity->getDurationMinutes();

        /** @var list<VenueTrainingSlot> $sameDay */
        $sameDay = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy([
            'venueId' => $entity->getVenueId(),
            'dayOfWeek' => $entity->getDayOfWeek(),
        ]);

        foreach ($sameDay as $other) {
            if ($other->getId() === $entity->getId()) {
                continue; // an edit does not conflict with itself
            }
            // Layers that are NEVER generated together may share a time: two DIFFERENT
            // periods (both schedulePlanId set and distinct) never union. But the
            // overlay build unions SEASONAL ∪ one period, so a period slot must not
            // overlap a seasonal one (else the same court is double-booked at solve).
            if ($this->neverGeneratedTogether($entity, $other)) {
                continue;
            }
            // #8 — un créneau de SAISON que CETTE période ignore (gymnase en « grille
            // vierge », ou créneau écarté) n'entrera jamais dans son payload : il ne peut
            // donc pas s'y télescoper avec un créneau prêté. Sans cette exception, « repartir
            // d'une grille vierge » serait inutilisable — redéfinir les heures du gymnase
            // buterait sur les créneaux de saison qu'on vient précisément d'écarter, et le
            // seul contournement serait de les SUPPRIMER, ce qui modifierait le planning
            // principal (revue #285 : invariant fondateur n°1).
            if ($this->ignoredByPeriodOf($entity, $other)) {
                continue;
            }
            $otherStart = $this->minutesOf($other);
            if ($start < $otherStart + $other->getDurationMinutes() && $otherStart < $end) {
                throw new ValidationException('This slot overlaps another slot of the same venue on that day.');
            }
        }
    }

    /**
     * Le créneau qu'on écrit est-il PRÊTÉ à une période qui ignore l'autre créneau ?
     * Vrai seulement dans ce sens : la période décide ce qu'elle ignore, la saison non.
     */
    private function ignoredByPeriodOf(VenueTrainingSlot $entity, VenueTrainingSlot $other): bool
    {
        $planId = $entity->getSchedulePlanId();
        if (null === $planId || null !== $other->getSchedulePlanId()) {
            return false; // on n'écrit pas pour une période, ou l'autre n'est pas saisonnier
        }

        $mode = $this->entityManager->getRepository(VenuePeriodOverride::class)
            ->findOneBy(['schedulePlanId' => $planId, 'venueId' => $other->getVenueId()])?->getMode();
        if (VenuePeriodMode::BLANK === $mode || VenuePeriodMode::DISABLED === $mode) {
            return true;
        }

        return null !== $this->entityManager->getRepository(VenueSlotPeriodExclusion::class)
            ->findOneBy(['schedulePlanId' => $planId, 'venueTrainingSlotId' => $other->getId()]);
    }

    private function neverGeneratedTogether(VenueTrainingSlot $a, VenueTrainingSlot $b): bool
    {
        return null !== $a->getSchedulePlanId()
            && null !== $b->getSchedulePlanId()
            && $a->getSchedulePlanId() !== $b->getSchedulePlanId();
    }

    private function minutesOf(VenueTrainingSlot $slot): int
    {
        return (int) $slot->getStartTime()->format('H') * 60 + (int) $slot->getStartTime()->format('i');
    }
}
