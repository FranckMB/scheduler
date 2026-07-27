<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\VenueTrainingSlotResource;
use App\Dto\VenueTrainingSlotInput;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<VenueTrainingSlot, VenueTrainingSlotInput, VenueTrainingSlotResource>
 */
class VenueTrainingSlotStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

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

    /**
     * Le flush a lieu dans le socle : la violation de FK d'une suppression de plan
     * CONCURRENTE ne peut être rattrapée qu'ici, autour de l'appel parent.
     *
     * @param VenueTrainingSlotInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * @param array<string, mixed>   $uriVariables
     * @param VenueTrainingSlotInput $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));
    }

    protected function createEntityFromInput(object $input): VenueTrainingSlot
    {
        $entity = new VenueTrainingSlot;
        // Period slot (schedulePlanId set) vs seasonal (null) — set only on create;
        // a slot never migrates between the seasonal and a period layer.
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
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
            $otherStart = $this->minutesOf($other);
            if ($start < $otherStart + $other->getDurationMinutes() && $otherStart < $end) {
                throw new ValidationException('This slot overlaps another slot of the same venue on that day.');
            }
        }
    }

    /**
     * Deux créneaux de PLANS DIFFÉRENTS ne sont jamais servis ensemble, donc ne peuvent
     * pas se télescoper — y compris un créneau de saison face à la COPIE qu'une période
     * en détient (#8) : depuis que la période possède sa grille, le socle est bâti sur
     * les créneaux de saison seuls et une période sur les siens seuls, sans union.
     *
     * Exiger (comme avant) que les DEUX portent un plan rendait la grille de saison
     * inéditable dès qu'une période existait : sa copie, identique heure pour heure,
     * était vue comme un chevauchement (revue #8). Le chevauchement RÉEL — deux créneaux
     * d'une même couche — reste refusé.
     */
    private function neverGeneratedTogether(VenueTrainingSlot $a, VenueTrainingSlot $b): bool
    {
        return $a->getSchedulePlanId() !== $b->getSchedulePlanId();
    }

    private function minutesOf(VenueTrainingSlot $slot): int
    {
        return (int) $slot->getStartTime()->format('H') * 60 + (int) $slot->getStartTime()->format('i');
    }
}
