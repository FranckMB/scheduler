<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\TeamMatchHabitResource;
use App\Dto\TeamMatchHabitInput;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use DateTimeImmutable;

/**
 * Wizard-surface structure entity (VenueMatchWindow idiom): not management
 * gated — the page's socle lock is the UI gate, the data is club structure.
 *
 * @extends AbstractStateProcessor<TeamMatchHabit, TeamMatchHabitInput, TeamMatchHabitResource>
 */
class TeamMatchHabitStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamMatchHabit::class;
    }

    /**
     * @param TeamMatchHabitInput $input
     */
    protected function createEntityFromInput(object $input): TeamMatchHabit
    {
        $entity = new TeamMatchHabit;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param TeamMatchHabit      $entity
     * @param TeamMatchHabitInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
        // '' explicitly clears the venue (PUT full-replace idiom).
        if ('' === $input->venueId) {
            $entity->setVenueId(null);
        }
    }

    /**
     * @param TeamMatchHabit $entity
     */
    protected function mapEntityToOutput(object $entity): TeamMatchHabitResource
    {
        return TeamMatchHabitResource::fromEntity($entity);
    }

    private function applyInput(TeamMatchHabit $entity, TeamMatchHabitInput $input): void
    {
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->kickoffTime) {
            $entity->setKickoffTime(new DateTimeImmutable($input->kickoffTime));
        }
        if (null !== $input->venueId && '' !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }

        // Foreign/unknown references resolve to null through the tenant+season
        // filters → 422. `findOneBy`, NOT `find()`: a PK load can serve the
        // identity map and skip the SQL filters (leçon PR B).
        if (!$this->entityManager->getRepository(Team::class)->findOneBy(['id' => $entity->getTeamId()]) instanceof Team) {
            throw new ValidationException('Unknown team for this club.');
        }
        if (null !== $entity->getVenueId() && !$this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $entity->getVenueId()]) instanceof Venue) {
            throw new ValidationException('Unknown venue for this club.');
        }
        // One habit per weekday and per team — the DB unique is the backstop,
        // this gives the manager a readable 422 instead of a 500.
        $existing = $this->entityManager->getRepository(TeamMatchHabit::class)->findOneBy([
            'teamId' => $entity->getTeamId(),
            'dayOfWeek' => $entity->getDayOfWeek(),
        ]);
        if ($existing instanceof TeamMatchHabit && $existing->getId() !== $entity->getId()) {
            throw new ValidationException('This team already has a habit on that day — edit it instead.');
        }
    }
}
