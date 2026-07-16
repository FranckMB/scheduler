<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Constraint;
use App\Entity\ConstraintConflict;
use App\Entity\Fixture;
use App\Entity\PeriodReminderLog;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes all data of a single (club, season): the canonical delete-order list,
 * shared by ResetSeasonController (wipe a season's contents, keep the row) and
 * PurgeSeasonsCommand (retention purge — deletes the Season row too).
 *
 * Runs under the caller's tenant context (RLS GUC must already be set to the
 * club). Disables the tenant/season Doctrine filters for the bulk DQL DELETEs
 * (they alias the table name, which is invalid SQL for the reserved-word
 * `constraint` table); the deletes are explicitly scoped by clubId + seasonId
 * and RLS still enforces the club boundary at the DB.
 */
final class SeasonDataPurger
{
    use DisablesTenantFilters;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * @return int number of child rows deleted (excludes the Season row itself)
     */
    public function purge(string $clubId, string $seasonId, bool $deleteSeasonRow = false): int
    {
        $this->disableTenantFilters($this->entityManager);

        // ADR-0002 inv. 12 : le nom du planning vit sur le plan — et le plan est
        // supprimé plus bas. Avant la bascule il vivait sur la saison et survivait donc
        // au reset ; il faut le capturer AVANT la purge, sinon « réinitialiser la
        // saison » renomme silencieusement le planning du gestionnaire.
        $seasonPlanName = $deleteSeasonRow ? null : $this->currentSeasonPlanName($seasonId);

        $deleted = 0;

        // Children WITHOUT club/season columns first, resolved through their
        // parent: conflicts hang off schedules, reminder logs off calendar
        // entries. They must go before their parents' bulk DELETE or they
        // orphan silently.
        $deleted += $this->deleteBySubQuery(ConstraintConflict::class, 'scheduleId', Schedule::class, $clubId, $seasonId);
        // SolverMetric porte un clubId mais PAS de seasonId : il se résout par son
        // planning, comme les conflits. Oublié, il survivait au reset ET à l'effacement
        // RGPD en nommant des plannings supprimés — aucune FK ne pend à `schedule`.
        $deleted += $this->deleteBySubQuery(\App\Entity\SolverMetric::class, 'scheduleId', Schedule::class, $clubId, $seasonId);
        $deleted += $this->deleteBySubQuery(PeriodReminderLog::class, 'calendarEntryId', CalendarEntry::class, $clubId, $seasonId);

        // TeamTagAssignment has a season_id but NO club_id → deleted by season.
        $deleted += (int) $this->entityManager->createQueryBuilder()
            ->delete(TeamTagAssignment::class, 'e')
            ->where('e.seasonId = :seasonId')
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();

        foreach ([
            ScheduleDiagnostic::class,
            \App\Entity\ScheduleStructureSnapshot::class,
            ScheduleSlotTemplate::class,
            Constraint::class,
            Reservation::class,
            \App\Entity\TeamPeriodOverride::class,
            \App\Entity\ConstraintPeriodOverride::class,
            // Module matchs (ajouté après ce purger — gap RGPD constaté PR-1) :
            // Fixture avant Competition (competitionId y pointe). Changement
            // ASSUMÉ pour ResetSeasonController aussi : « réinitialiser la
            // saison » supprime désormais matchs/compétitions/réservations —
            // l'ancien comportement les gardait ORPHELINS (fixtures pointant
            // des équipes supprimées), ce qui était le vrai bug.
            Fixture::class,
            Competition::class,
            TeamCoach::class,
            CoachPlayerMembership::class,
            CalendarEntry::class,
            // ADR-0002: the named container of a season/period's versions — a
            // club_id+season_id table, so it must be purged with the season
            // (RGPD erasure + retention purge + season reset). No DB FK cascades.
            \App\Entity\SchedulePlan::class,
            Schedule::class,
            Team::class,
            Coach::class,
            VenueTrainingSlot::class,
            Venue::class,
        ] as $entityClass) {
            $deleted += $this->deleteByClubSeason($entityClass, $clubId, $seasonId);
        }

        if ($deleteSeasonRow) {
            $this->entityManager->createQueryBuilder()
                ->delete(Season::class, 's')
                ->where('s.clubId = :clubId')
                ->andWhere('s.id = :seasonId')
                ->setParameter('clubId', $clubId)
                ->setParameter('seasonId', $seasonId)
                ->getQuery()
                ->execute();
        } else {
            // Keep the Season row but drop its loaded-context star: it names a
            // schedule the purge just deleted. The plan's pointer goes with the
            // plan rows above — a plan naming a deleted planning would keep the
            // cockpit "unlocked" with nothing behind it.
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
            if ($season instanceof Season && $season->getClubId() === $clubId) {
                $season->setLiveContextScheduleId(null);
                // ADR-0002: the reset wiped the season's SchedulePlan above, but the
                // season row survives — re-provision its empty SEASON plan so the
                // invariant "a SEASON plan exists as soon as the season does" holds.
                $plan = $this->schedulePlanProvisioner->ensureSeasonPlan($season);
                // Le reset vide les DONNÉES de la saison ; il ne rebaptise pas son
                // planning. Le nom re-provisionné est un défaut — on rend au plan celui
                // que le gestionnaire avait choisi.
                if (null !== $seasonPlanName && '' !== $seasonPlanName) {
                    $plan->setName($seasonPlanName);
                }
                $this->entityManager->flush();
            }
        }

        $this->entityManager->clear();

        return $deleted;
    }

    /**
     * Le nom du plan SEASON tel qu'il est AVANT la purge. SQL brut : le plan est
     * supprimé en DQL de masse juste après, et on ne veut pas d'une entité gérée qui
     * ressusciterait au flush.
     */
    private function currentSeasonPlanName(string $seasonId): ?string
    {
        $name = $this->entityManager->getConnection()->fetchOne(
            'SELECT name FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return \is_string($name) ? $name : null;
    }

    /**
     * Delete rows of $entityClass whose $parentRefField points at a parent row
     * (of $parentClass) belonging to this club+season. DQL DELETE with subquery.
     */
    private function deleteBySubQuery(string $entityClass, string $parentRefField, string $parentClass, string $clubId, string $seasonId): int
    {
        $sub = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from($parentClass, 'p')
            ->where('p.clubId = :clubId')
            ->andWhere('p.seasonId = :seasonId')
            ->getDQL();

        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where(\sprintf('e.%s IN (%s)', $parentRefField, $sub))
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }

    private function deleteByClubSeason(string $entityClass, string $clubId, string $seasonId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }
}
