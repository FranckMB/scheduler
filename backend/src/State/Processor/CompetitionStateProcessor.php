<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CompetitionResource;
use App\Dto\CompetitionInput;
use App\Entity\Competition;
use App\Enum\CompetitionType;
use DateTimeImmutable;

/**
 * @extends AbstractStateProcessor<Competition, CompetitionInput, CompetitionResource>
 */
class CompetitionStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /**
     * @param CompetitionInput $input
     */
    protected function createEntityFromInput(object $input): Competition
    {
        $entity = new Competition;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->competitionType) {
            $entity->setCompetitionType(CompetitionType::from($input->competitionType));
        }
        $entity->setStartDate($this->parseDate($input->startDate));
        $entity->setEndDate($this->parseDate($input->endDate));

        return $entity;
    }

    /**
     * @param Competition      $entity
     * @param CompetitionInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->competitionType) {
            $entity->setCompetitionType(CompetitionType::from($input->competitionType));
        }
        if (null !== $input->startDate) {
            $entity->setStartDate($this->parseDate($input->startDate));
        }
        if (null !== $input->endDate) {
            $entity->setEndDate($this->parseDate($input->endDate));
        }
    }

    /**
     * @param Competition $entity
     */
    protected function mapEntityToOutput(object $entity): CompetitionResource
    {
        return CompetitionResource::fromEntity($entity);
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        return null === $value || '' === $value ? null : new DateTimeImmutable($value);
    }
}
