<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\TeamPeriodOverrideResource;
use App\Dto\TeamPeriodOverrideInput;
use App\Entity\CalendarEntry;
use App\Entity\TeamPeriodOverride;

/**
 * @extends AbstractStateProcessor<TeamPeriodOverride, TeamPeriodOverrideInput, TeamPeriodOverrideResource>
 */
class TeamPeriodOverrideStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return TeamPeriodOverride::class;
    }

    /**
     * @param TeamPeriodOverrideInput $input
     */
    protected function createEntityFromInput(object $input): TeamPeriodOverride
    {
        // One override per (period, team) — the DB unique index would otherwise
        // surface as a 500 on a double-submit; give a clean 422 instead (edit via PUT).
        if (null !== $input->calendarEntryId && null !== $input->teamId
            && null !== $this->entityManager->getRepository(TeamPeriodOverride::class)->findOneBy(['calendarEntryId' => $input->calendarEntryId, 'teamId' => $input->teamId])) {
            throw new ValidationException('This team already has an override for this period — edit it instead.');
        }

        $entity = new TeamPeriodOverride;
        if (null !== $input->calendarEntryId) {
            $entity->setCalendarEntryId($input->calendarEntryId);
        }
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        $entity->setIsActive($input->isActive);
        $entity->setSessionsPerWeek($input->sessionsPerWeek);

        // Mark the period configured on its first override write, so the wizard's
        // Fanion-only seed runs once and never re-fires after an all-active reset
        // (survives reload, unlike a client-side guard). Flushed with the override.
        if (null !== $input->calendarEntryId) {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($input->calendarEntryId);
            if ($entry instanceof CalendarEntry && !$entry->isTeamSelectionInitialized()) {
                $entry->setTeamSelectionInitialized(true);
            }
        }

        return $entity;
    }

    /**
     * @param TeamPeriodOverride      $entity
     * @param TeamPeriodOverrideInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // calendarEntryId + teamId identify the row — not remapped on edit.
        $entity->setIsActive($input->isActive);
        $entity->setSessionsPerWeek($input->sessionsPerWeek);
    }

    /**
     * @param TeamPeriodOverride $entity
     */
    protected function mapEntityToOutput(object $entity): TeamPeriodOverrideResource
    {
        return TeamPeriodOverrideResource::fromEntity($entity);
    }
}
