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
     * Le 1er override d'une période marque son PLAN comme configuré, pour que le seed
     * Fanion-only du wizard ne se rejoue jamais après un retour « tout actif » (0
     * override épars) — signal durable, contrairement à un garde côté client.
     *
     * ATOMIQUE avec l'override, et écrit APRÈS lui : le flag ne doit pas pouvoir rester
     * vrai sans qu'aucun override n'existe (le wizard cesserait alors de seeder une
     * période pourtant vierge). En createEntityFromInput, l'UPDATE brut s'auto-commettait
     * avant même le persist (round 2 du code-review).
     *
     * SQL BRUT plutôt que l'ORM, pour la raison que documente SchedulePlanProvisioner :
     * `season_filter` épingle toute lecture ORM à la saison ACTIVE de la requête, or
     * `calendarEntryId` arrive dans le corps sans être validé contre elle — un findOneBy
     * filtré rendrait null pour une période d'une autre saison et l'échec serait avalé en
     * silence. RLS continue de scoper le club. Le `= false` rend l'écriture idempotente.
     *
     * @param TeamPeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $output = parent::processPost($input, $clubId, $seasonId);
            if (null !== $input->calendarEntryId) {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE schedule_plan SET team_selection_initialized = true, updated_at = now(), version = version + 1 '
                    . 'WHERE calendar_entry_id = :eid AND team_selection_initialized = false',
                    ['eid' => $input->calendarEntryId],
                );
            }

            return $output;
        });
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
