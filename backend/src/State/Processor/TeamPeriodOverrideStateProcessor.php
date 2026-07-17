<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\TeamPeriodOverrideResource;
use App\Dto\TeamPeriodOverrideInput;
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

        // Mark the PLAN configured on its first override write, so the wizard's
        // Fanion-only seed runs once and never re-fires after an all-active reset
        // (survives reload, unlike a client-side guard). Flushed with the override.
        //
        // Lot C: the flag lives on the plan (inv. 5 — the settings hang off the plan,
        // not off the calendar event). The override is still keyed by calendarEntryId
        // until C2, so the plan is resolved through it; C2 will make that a direct
        // planId read. The plan exists by now — it is born with the entry (lot C).
        //
        // SQL BRUT, pas l'ORM, pour la raison que documente SchedulePlanProvisioner :
        // `season_filter` épingle toute lecture ORM à la saison ACTIVE de la requête,
        // or `calendarEntryId` arrive dans le corps sans être validé contre elle. Un
        // findOneBy filtré rendrait null pour une période d'une autre saison, et le
        // `instanceof` avalerait l'échec en silence : le flag ne serait jamais posé, et
        // le wizard re-seederait le Fanion-only par-dessus les choix du gestionnaire.
        // RLS continue de scoper le club.
        if (null !== $input->calendarEntryId) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE schedule_plan SET team_selection_initialized = true, updated_at = now(), version = version + 1 '
                . 'WHERE calendar_entry_id = :eid AND team_selection_initialized = false',
                ['eid' => $input->calendarEntryId],
            );
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
