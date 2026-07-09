<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ReservationResource;
use App\Dto\ReservationInput;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
     * Deleting a reservation must UNDO its pin. A reservation is echoed HARD in
     * the solver output and materialised by ScheduleResultImporter as a durable
     * HARD ScheduleSlotTemplate; findBaseSlotTemplates would otherwise re-inject
     * that orphaned pin on every future generation, so purge the matching
     * materialised template(s) alongside the reservation.
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $reservation = $this->entityManager->find(Reservation::class, $uriVariables['id'] ?? null);
        if (!$reservation instanceof Reservation) {
            throw new NotFoundHttpException('Resource not found');
        }
        if (null !== $clubId && $reservation->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Access denied');
        }

        $materialised = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'clubId' => $reservation->getClubId(),
            'seasonId' => $reservation->getSeasonId(),
            'teamId' => $reservation->getTeamId(),
            'venueId' => $reservation->getVenueId(),
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startTime' => $reservation->getStartTime(),
            'lockLevel' => LockLevel::HARD,
        ]);
        foreach ($materialised as $template) {
            $this->entityManager->remove($template);
        }

        $this->entityManager->remove($reservation);
        $this->entityManager->flush();
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
        if (null !== $input->startTime) {
            $entity->setStartTime($input->startTime);
        }
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
