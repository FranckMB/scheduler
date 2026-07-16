<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\TeamResource;
use App\Dto\TeamInput;
use App\Entity\Team;
use App\Enum\Gender;
use App\Enum\TeamLevel;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\TeamEngagementGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends AbstractStateProcessor<Team, TeamInput, TeamResource>
 */
class TeamStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly TeamEngagementGuard $teamEngagementGuard,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Team::class;
    }

    /**
     * Une équipe ENGAGÉE (elle joue déjà) n'est pas supprimable : ses matchs sont
     * connus de la fédération, et `purgeChildrenOfTeam` les emporterait avec elle.
     *
     * Le refus vit ICI, avant `parent::processDelete` — donc avant la cascade, qui
     * supprime les Fixture. Le placer plus bas ne refuserait rien : les matchs
     * seraient déjà détruits.
     *
     * @param array<string, mixed> $uriVariables
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $id = $uriVariables['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            $team = $this->entityManager->getRepository(Team::class)->find($id);
            if ($team instanceof Team && (null === $clubId || $team->getClubId() === $clubId)) {
                $this->teamEngagementGuard->assertNotEngaged(
                    $team->getId(),
                    'Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération. Elle ne peut plus être supprimée.',
                );
            }
        }

        parent::processDelete($uriVariables, $clubId);
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof Team) {
            $this->cascadeDeleter?->purgeChildrenOfTeam($entity);
        }
    }

    /**
     * @param TeamInput $input
     */
    protected function createEntityFromInput(object $input): Team
    {
        $entity = new Team;
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->tierOrder) {
            $entity->setTierOrder($input->tierOrder);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->gender) {
            $gender = Gender::tryFrom($input->gender);
            if (null !== $gender) {
                $entity->setGender($gender);
            }
        }
        $entity->setLevel(null !== $input->level ? TeamLevel::tryFrom($input->level) : null);
        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? true);
        $entity->setParentTeamId($input->parentTeamId);

        return $entity;
    }

    /**
     * @param Team      $entity
     * @param TeamInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $entity->setSportCategoryId($input->sportCategoryId ?? '33333333-3333-3333-3333-333333333333');
        $entity->setPriorityTierId($input->priorityTierId ?? 1);
        if (null !== $input->tierOrder) {
            $entity->setTierOrder($input->tierOrder);
        }
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->gender) {
            $gender = Gender::tryFrom($input->gender);
            if (null !== $gender) {
                $entity->setGender($gender);
            }
        }

        // Le NIVEAU d'une équipe engagée est figé : c'est sous ce niveau qu'elle est
        // inscrite auprès de la fédération. Le tier, lui, reste libre — c'est la
        // perception interne du club, elle peut bouger sans rien remettre en cause.
        //
        // Seul un CHANGEMENT est refusé, pas un écho : le front renvoie le payload
        // complet à chaque PUT, donc refuser une valeur identique casserait un simple
        // renommage d'équipe.
        $newLevel = null !== $input->level ? TeamLevel::tryFrom($input->level) : null;
        if ($newLevel !== $entity->getLevel()) {
            $this->teamEngagementGuard->assertNotEngaged(
                $entity->getId(),
                'Cette équipe joue en compétition : elle est inscrite sous son niveau actuel auprès de la fédération, il ne peut plus changer.',
            );
        }
        $entity->setLevel($newLevel);

        if (null !== $input->sessionsPerWeek) {
            $entity->setSessionsPerWeek($input->sessionsPerWeek);
        }
        $entity->setMinSessionsOverride($input->minSessionsOverride);
        $entity->setMatchDay($input->matchDay);
        $entity->setForcedVenueId($input->forcedVenueId);
        $entity->setIsActive($input->isActive ?? $entity->getIsActive());
        $entity->setParentTeamId($input->parentTeamId);
    }

    /**
     * @param Team $entity
     */
    protected function mapEntityToOutput(object $entity): TeamResource
    {
        return TeamResource::fromEntity($entity);
    }
}
