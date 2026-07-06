<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\FixtureResource;
use App\Dto\FixtureInput;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<Fixture, FixtureInput, FixtureResource>
 */
class FixtureStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Fixture::class;
    }

    /**
     * @param FixtureInput $input
     */
    protected function createEntityFromInput(object $input): Fixture
    {
        $entity = new Fixture;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        // competitionId nullable → friendly; explicit null clears it.
        $entity->setCompetitionId('' === $input->competitionId ? null : $input->competitionId);
        if (null !== $input->matchDate) {
            $entity->setMatchDate(new DateTimeImmutable($input->matchDate));
        }
        if (null !== $input->homeAway) {
            $entity->setHomeAway(FixtureHomeAway::from($input->homeAway));
        }
        if (null !== $input->opponentLabel) {
            $entity->setOpponentLabel($input->opponentLabel);
        }
        if (null !== $input->status) {
            $entity->setStatus(FixtureStatus::from($input->status));
        }
        $entity->setVenueId('' === $input->venueId ? null : $input->venueId);
        $entity->setKickoffTime($this->parseTime($input->kickoffTime));

        return $entity;
    }

    /**
     * @param Fixture      $entity
     * @param FixtureInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->competitionId) {
            $entity->setCompetitionId('' === $input->competitionId ? null : $input->competitionId);
        }
        if (null !== $input->matchDate) {
            $entity->setMatchDate(new DateTimeImmutable($input->matchDate));
        }
        if (null !== $input->homeAway) {
            $entity->setHomeAway(FixtureHomeAway::from($input->homeAway));
        }
        if (null !== $input->opponentLabel) {
            $entity->setOpponentLabel($input->opponentLabel);
        }
        if (null !== $input->status) {
            $entity->setStatus(FixtureStatus::from($input->status));
        }
        if (null !== $input->venueId) {
            $entity->setVenueId('' === $input->venueId ? null : $input->venueId);
        }
        if (null !== $input->kickoffTime) {
            $entity->setKickoffTime($this->parseTime($input->kickoffTime));
        }
    }

    /**
     * @param Fixture $entity
     */
    protected function mapEntityToOutput(object $entity): FixtureResource
    {
        return FixtureResource::fromEntity($entity);
    }

    private function parseTime(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!H:i', $value);

        return false === $time ? null : $time;
    }
}
