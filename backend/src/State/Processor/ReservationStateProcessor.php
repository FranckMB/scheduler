<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ReservationResource;
use App\Dto\ReservationInput;
use App\Entity\Reservation;

/**
 * @extends AbstractStateProcessor<Reservation, ReservationInput, ReservationResource>
 */
class ReservationStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Reservation::class;
    }

    /**
     * @param ReservationInput $input
     */
    protected function createEntityFromInput(object $input): Reservation
    {
        // clubId + seasonId are set by AbstractStateProcessor from the tenant/season
        // context. No SEC-07 management gate — reservations are a wizard write, like
        // constraints and venue slots.
        $entity = new Reservation;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        $entity->setStartTime($input->startTime);
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        $entity->setCalendarEntryId($input->calendarEntryId);

        return $entity;
    }

    /**
     * @param Reservation      $entity
     * @param ReservationInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // No PUT operation — reservations are created or deleted, not edited.
    }

    /**
     * @param Reservation $entity
     */
    protected function mapEntityToOutput(object $entity): ReservationResource
    {
        return ReservationResource::fromEntity($entity);
    }
}
