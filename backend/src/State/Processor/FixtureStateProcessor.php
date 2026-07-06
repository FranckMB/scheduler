<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\FixtureResource;
use App\Dto\FixtureInput;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
        // competitionId nullable → friendly; explicit '' clears it.
        $competitionId = '' === $input->competitionId ? null : $input->competitionId;
        $this->assertCompetitionInScope($competitionId);
        $entity->setCompetitionId($competitionId);
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
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->competitionId) {
            $competitionId = '' === $input->competitionId ? null : $input->competitionId;
            $this->assertCompetitionInScope($competitionId);
            $entity->setCompetitionId($competitionId);
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
        // Reject anything createFromFormat rolled over (belt-and-braces; the DTO
        // regex already blocks out-of-range HH:MM before we get here).
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $time || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $time;
    }

    /**
     * A referenced competition must belong to the caller's club+season. em->find
     * is tenant+season-filter aware (enabled per-request), so a foreign, deleted
     * or nonexistent id resolves to null → 422 rather than a dangling reference.
     */
    private function assertCompetitionInScope(?string $competitionId): void
    {
        if (null === $competitionId) {
            return;
        }
        if (null === $this->entityManager->find(Competition::class, $competitionId)) {
            throw new UnprocessableEntityHttpException('Unknown competition for this club/season.');
        }
    }
}
