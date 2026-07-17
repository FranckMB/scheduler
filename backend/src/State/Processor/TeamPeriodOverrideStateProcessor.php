<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\TeamPeriodOverrideResource;
use App\Dto\TeamPeriodOverrideInput;
use App\Entity\TeamPeriodOverride;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<TeamPeriodOverride, TeamPeriodOverrideInput, TeamPeriodOverrideResource>
 */
class TeamPeriodOverrideStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return TeamPeriodOverride::class;
    }

    /**
     * Le 1er override d'une période marque son PLAN comme configuré (garde de seed du
     * wizard). Délégué à SchedulePlanProvisioner : le code en fait le SEUL point qui
     * écrit des lignes `schedule_plan`, et son docblock porte le pourquoi du SQL brut.
     *
     * ATOMIQUE avec l'override, et écrit APRÈS lui : le flag ne doit pas pouvoir rester
     * vrai sans qu'aucun override n'existe — le wizard cesserait alors de seeder une
     * période pourtant vierge.
     *
     * @param TeamPeriodOverrideInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->entityManager->wrapInTransaction(function () use ($input, $clubId, $seasonId): object {
            $output = parent::processPost($input, $clubId, $seasonId);
            if (null !== $input->calendarEntryId) {
                $this->schedulePlanProvisioner->markPeriodTeamSelectionInitialized($input->calendarEntryId);
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
