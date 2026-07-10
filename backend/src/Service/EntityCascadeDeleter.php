<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cascade the logical children of a single deleted entity (team / venue / coach
 * / availability slot). Unlike SeasonDataPurger (whole-season wipe), this runs
 * on a per-entity Delete: the entities carry no ORM associations or DB foreign
 * keys — every link is a plain guid column — so nothing cascades on its own and
 * a bare remove() would orphan reservations, coach links, constraints and the
 * materialised HARD slot templates the solver reads back.
 *
 * Runs in the request's tenant context (RLS GUC already set to the club by
 * TenantFilterListener). The bulk DQL DELETE/UPDATE are scoped by the entity's
 * clubId + seasonId AND guarded by RLS at the DB — a cascade can never cross a
 * club. The tenant/season Doctrine filters are disabled around the statements:
 * like SeasonDataPurger, they alias the table name (invalid SQL for the
 * reserved-word `constraint` table); the explicit scope + RLS replace them.
 *
 * The caller (the state processor) removes the entity itself afterwards.
 */
final class EntityCascadeDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function purgeChildrenOfTeam(Team $team): void
    {
        $clubId = $team->getClubId();
        $seasonId = $team->getSeasonId();
        $teamId = $team->getId();

        $this->withoutTenantFilters(function () use ($clubId, $seasonId, $teamId): void {
            $this->deleteByField(Reservation::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(TeamCoach::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(CoachPlayerMembership::class, 'teamId', $teamId, $clubId, $seasonId);
            $this->deleteByField(ScheduleSlotTemplate::class, 'teamId', $teamId, $clubId, $seasonId);
            // TeamTagAssignment has a season_id but NO club_id (scoped by season).
            $this->deleteByField(TeamTagAssignment::class, 'teamId', $teamId, null, $seasonId);
            $this->deleteScopedConstraint(ConstraintScope::TEAM, $teamId, $clubId, $seasonId);
            $this->clearParentRef(Team::class, 'parentTeamId', $teamId, $clubId, $seasonId);
        });
    }

    public function purgeChildrenOfVenue(Venue $venue): void
    {
        $clubId = $venue->getClubId();
        $seasonId = $venue->getSeasonId();
        $venueId = $venue->getId();

        $this->withoutTenantFilters(function () use ($clubId, $seasonId, $venueId): void {
            $this->deleteByField(VenueTrainingSlot::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(Reservation::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteByField(ScheduleSlotTemplate::class, 'venueId', $venueId, $clubId, $seasonId);
            $this->deleteScopedConstraint(ConstraintScope::FACILITY, $venueId, $clubId, $seasonId);
            $this->clearParentRef(Team::class, 'forcedVenueId', $venueId, $clubId, $seasonId);
            $this->clearParentRef(Venue::class, 'parentVenueId', $venueId, $clubId, $seasonId);
        });
    }

    public function purgeChildrenOfCoach(Coach $coach): void
    {
        $clubId = $coach->getClubId();
        $seasonId = $coach->getSeasonId();
        $coachId = $coach->getId();

        $this->withoutTenantFilters(function () use ($clubId, $seasonId, $coachId): void {
            $this->deleteByField(TeamCoach::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->deleteByField(CoachPlayerMembership::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->deleteScopedConstraint(ConstraintScope::COACH, $coachId, $clubId, $seasonId);
            // A slot placement keeps existing without its (now-deleted) coach —
            // the engine leaves slot.coachId empty anyway, so null it out.
            $this->clearParentRef(ScheduleSlotTemplate::class, 'coachId', $coachId, $clubId, $seasonId);
            $this->clearParentRef(Coach::class, 'parentCoachId', $coachId, $clubId, $seasonId);
        });
    }

    /**
     * A slot is a leaf, but a Reservation pins to it LOGICALLY by (venueId,
     * dayOfWeek, startTime) — deleting the slot must clear those reservations
     * and their materialised HARD templates (same idiom as
     * ReservationStateProcessor), else they re-inject a pin on a slot that no
     * longer exists (the orphan-reservation bug this whole change fixes).
     */
    public function purgeChildrenOfSlot(VenueTrainingSlot $slot): void
    {
        $clubId = $slot->getClubId();
        $seasonId = $slot->getSeasonId();

        $this->withoutTenantFilters(function () use ($slot, $clubId, $seasonId): void {
            foreach ([Reservation::class, ScheduleSlotTemplate::class] as $entityClass) {
                $this->entityManager->createQueryBuilder()
                    ->delete($entityClass, 'e')
                    ->where('e.clubId = :clubId')
                    ->andWhere('e.seasonId = :seasonId')
                    ->andWhere('e.venueId = :venueId')
                    ->andWhere('e.dayOfWeek = :dayOfWeek')
                    ->andWhere('e.startTime = :startTime')
                    ->setParameter('clubId', $clubId)
                    ->setParameter('seasonId', $seasonId)
                    ->setParameter('venueId', $slot->getVenueId())
                    ->setParameter('dayOfWeek', $slot->getDayOfWeek())
                    ->setParameter('startTime', $slot->getStartTime())
                    ->getQuery()
                    ->execute();
            }
        });
    }

    private function deleteByField(string $entityClass, string $field, string $value, ?string $clubId, string $seasonId): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where(\sprintf('e.%s = :value', $field))
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('value', $value)
            ->setParameter('seasonId', $seasonId);
        if (null !== $clubId) {
            $qb->andWhere('e.clubId = :clubId')->setParameter('clubId', $clubId);
        }
        $qb->getQuery()->execute();
    }

    private function deleteScopedConstraint(ConstraintScope $scope, string $targetId, string $clubId, string $seasonId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(Constraint::class, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->andWhere('e.scope = :scope')
            ->andWhere('e.scopeTargetId = :target')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('scope', $scope)
            ->setParameter('target', $targetId)
            ->getQuery()
            ->execute();
    }

    /** Null out a (self- or cross-) reference column pointing at the deleted id. */
    private function clearParentRef(string $entityClass, string $field, string $value, string $clubId, string $seasonId): void
    {
        $this->entityManager->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set(\sprintf('e.%s', $field), 'NULL')
            ->where(\sprintf('e.%s = :value', $field))
            ->andWhere('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('value', $value)
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }

    /**
     * Run $work with the tenant/season Doctrine filters disabled (same as
     * SeasonDataPurger): they alias the table name, invalid SQL for the
     * reserved-word `constraint` table. NOT re-enabled — Doctrine drops a
     * filter's bound parameters when it is disabled then re-enabled (the next
     * query would throw "Parameter 'season_id' does not exist"). Leaving them
     * off is safe: this runs in the DELETE processor, whose 204 response needs
     * no further tenant-scoped read, and the per-request EM dies after. RLS
     * still enforces the club boundary at the DB, plus the explicit clubId +
     * seasonId scope on every statement.
     */
    private function withoutTenantFilters(callable $work): void
    {
        $filters = $this->entityManager->getFilters();
        foreach (['tenant_filter', 'season_filter'] as $filterName) {
            if ($filters->isEnabled($filterName)) {
                $filters->disable($filterName);
            }
        }

        $work();
    }
}
