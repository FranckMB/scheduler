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
    ) {}

    /**
     * @return int number of child rows deleted (excludes the Season row itself)
     */
    public function purge(string $clubId, string $seasonId, bool $deleteSeasonRow = false): int
    {
        $this->disableTenantFilters($this->entityManager);

        $deleted = 0;

        // Children WITHOUT club/season columns first, resolved through their
        // parent: conflicts hang off schedules, reminder logs off calendar
        // entries. They must go before their parents' bulk DELETE or they
        // orphan silently.
        $deleted += $this->deleteBySubQuery(ConstraintConflict::class, 'scheduleId', Schedule::class, $clubId, $seasonId);
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
            // Keep the Season row but clear its anchors: the baseline points at
            // a deleted schedule, and socleValidatedAt is sticky — leaving them
            // would keep the cockpit "unlocked" with no plan behind it.
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
            if ($season instanceof Season && $season->getClubId() === $clubId) {
                $season->setBaselineScheduleId(null);
                $season->setLiveContextScheduleId(null);
                $season->setSocleValidatedAt(null);
                $this->entityManager->flush();
            }
        }

        $this->entityManager->clear();

        return $deleted;
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
