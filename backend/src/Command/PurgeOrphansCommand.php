<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Service\DisablesTenantFilters;
use App\Service\TenantConnectionContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * One-shot cleanup of the logical orphans that accumulated BEFORE per-entity
 * cascade delete existed (EntityCascadeDeleter): rows whose parent was deleted
 * by a bare remove(). The dangerous ones are orphan Reservations (they still
 * feed the solver as HARD pre-placements on a venue slot that no longer exists)
 * and their materialised HARD templates; also the dangling coach links.
 *
 * Walks clubs on the runtime (RLS) connection like PurgeSeasonsCommand — each
 * club under its own GUC, so every scan/delete is scoped to that tenant at the
 * DB. Dry-run by default; --force actually deletes. Manual, never auto-runs.
 */
#[AsCommand(
    name: 'app:purge-orphans',
    description: 'Delete logical orphans left by pre-cascade deletes (orphan reservations, dangling links). Manual.',
)]
final class PurgeOrphansCommand extends Command
{
    use DisablesTenantFilters;

    /** DQL fragments identifying each orphan class, keyed by a human label. Each references its own alias. */
    private const ORPHAN_DQL = [
        'réservations sans créneau' => [
            'entity' => \App\Entity\Reservation::class,
            'alias' => 'r',
            'where' => 'NOT EXISTS (SELECT 1 FROM App\Entity\VenueTrainingSlot s WHERE s.clubId = r.clubId AND s.seasonId = r.seasonId AND s.venueId = r.venueId AND s.dayOfWeek = r.dayOfWeek AND s.startTime = r.startTime)',
        ],
        'liens équipe-coach pendants' => [
            'entity' => \App\Entity\TeamCoach::class,
            'alias' => 'tc',
            'where' => 'NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = tc.teamId) OR NOT EXISTS (SELECT 1 FROM App\Entity\Coach c WHERE c.id = tc.coachId)',
        ],
        'liens coach-joueur pendants' => [
            'entity' => \App\Entity\CoachPlayerMembership::class,
            'alias' => 'cp',
            'where' => 'NOT EXISTS (SELECT 1 FROM App\Entity\Coach c WHERE c.id = cp.coachId) OR NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = cp.teamId)',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete (default is a dry-run count).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $clubs = $this->entityManager->getRepository(Club::class)->findAll();
        $this->entityManager->clear();

        $totals = [];
        $hadFailure = false;
        foreach ($clubs as $club) {
            try {
                foreach ($this->purgeClub($club->getId(), $force) as $label => $count) {
                    $totals[$label] = ($totals[$label] ?? 0) + $count;
                }
            } catch (Throwable $e) {
                $hadFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $verb = $force ? 'supprimé(s)' : 'à supprimer (dry-run)';
        foreach ($totals as $label => $count) {
            $io->writeln(\sprintf('  %d %s %s', $count, $label, $verb));
        }
        if (!$force) {
            $io->note('Dry-run : relancez avec --force pour supprimer.');
        }

        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array<string, int> orphan counts by label for this club */
    private function purgeClub(string $clubId, bool $force): array
    {
        $this->tenantConnectionContext->setClubId($clubId);
        // Commands don't go through the web tenant listener, but disable the
        // filters defensively (they alias tables, breaking these subqueries).
        $this->disableTenantFilters($this->entityManager);

        $counts = [];
        foreach (self::ORPHAN_DQL as $label => $spec) {
            $counts[$label] = $this->countOrDelete($spec['entity'], $spec['alias'], $spec['where'], [], $force);
        }
        // HARD templates whose availability slot is GONE (deleted pre-cascade) —
        // team+venue may still exist, so keying on the slot is what catches the
        // phantom forced pin this whole change targets — or whose team is gone.
        $counts['templates HARD orphelins'] = $this->countOrDelete(
            ScheduleSlotTemplate::class,
            'st',
            'st.lockLevel = :hard AND (NOT EXISTS (SELECT 1 FROM App\Entity\VenueTrainingSlot s WHERE s.venueId = st.venueId AND s.dayOfWeek = st.dayOfWeek AND s.startTime = st.startTime) OR NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = st.teamId))',
            ['hard' => LockLevel::HARD],
            $force,
        );
        // Constraints whose scoped target (team / coach / venue) was deleted
        // before cascade existed — ScheduleConstraintBuilder still feeds them.
        $counts['contraintes sans cible'] = $this->countOrDelete(
            Constraint::class,
            'c',
            '(c.scope = :team AND NOT EXISTS (SELECT 1 FROM App\Entity\Team t WHERE t.id = c.scopeTargetId)) '
            . 'OR (c.scope = :coach AND NOT EXISTS (SELECT 1 FROM App\Entity\Coach co WHERE co.id = c.scopeTargetId)) '
            . 'OR (c.scope = :facility AND NOT EXISTS (SELECT 1 FROM App\Entity\Venue v WHERE v.id = c.scopeTargetId))',
            ['team' => ConstraintScope::TEAM, 'coach' => ConstraintScope::COACH, 'facility' => ConstraintScope::FACILITY],
            $force,
        );

        return $counts;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return int rows matched (dry-run) or deleted (--force)
     */
    private function countOrDelete(string $entity, string $alias, string $where, array $params, bool $force): int
    {
        if (!$force) {
            $dql = \sprintf('SELECT COUNT(%s.id) FROM %s %s WHERE %s', $alias, $entity, $alias, $where);
            $query = $this->entityManager->createQuery($dql);
            foreach ($params as $k => $v) {
                $query->setParameter($k, $v);
            }

            return (int) $query->getSingleScalarResult();
        }

        $dql = \sprintf('DELETE %s %s WHERE %s', $entity, $alias, $where);
        $query = $this->entityManager->createQuery($dql);
        foreach ($params as $k => $v) {
            $query->setParameter($k, $v);
        }

        return (int) $query->execute();
    }
}
