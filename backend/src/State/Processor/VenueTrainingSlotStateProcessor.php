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
        // Period slot (calendarEntryId set) vs seasonal (null) — set only on create;
        // a slot never migrates between the seasonal and a period layer.
        $entity->setCalendarEntryId($input->calendarEntryId);
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

        // Scoped to the SAME layer (seasonal-vs-seasonal, or same period): a period
        // slot is an independent overlay layer, it may legitimately sit at the same
        // time as a seasonal slot of another gym — only same-layer stacking is barred.
        /** @var list<VenueTrainingSlot> $sameDay */
        $sameDay = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy([
            'venueId' => $entity->getVenueId(),
            'dayOfWeek' => $entity->getDayOfWeek(),
            'calendarEntryId' => $entity->getCalendarEntryId(),
        ]);

        foreach ($sameDay as $other) {
            if ($other->getId() === $entity->getId()) {
                continue; // an edit does not conflict with itself
            }
            $otherStart = $this->minutesOf($other);
            if ($start < $otherStart + $other->getDurationMinutes() && $otherStart < $end) {
                throw new ValidationException('This slot overlaps another slot of the same venue on that day.');
            }
        }
    }

    private function minutesOf(VenueTrainingSlot $slot): int
    {
        return (int) $slot->getStartTime()->format('H') * 60 + (int) $slot->getStartTime()->format('i');
    }
}
