<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\ScheduleStatus;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * BCK-01 watchdog. A schedule left in PENDING/GENERATING past a deadline is a
 * zombie: the worker crashed/OOM-ed mid-solve, the message was lost, or the
 * lock-contention retries were exhausted and dropped. None of these throws an
 * exception the handler can catch, so nothing else flips the row to a terminal
 * status. This command reconciles them → FAILED + a diagnostic + a Mercure
 * terminal event so the frontend stops spinning.
 *
 * It walks clubs on the runtime (RLS-restricted) connection: the club table
 * carries no club_id and has no RLS policy, so it is readable with an empty
 * GUC; each club's schedules are then read/mutated under that club's GUC. No
 * superadmin door needed — the watchdog stays inside the tenant model.
 */
#[AsCommand(
    name: 'app:schedules:reconcile-stuck',
    description: 'Fail schedules stuck in PENDING/GENERATING past a deadline (worker crash / lost message / lock-exhaustion).',
)]
final class ReconcileStuckSchedulesCommand extends Command
{
    private const DEFAULT_MINUTES = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly HubInterface $hub,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Age in minutes past which a non-terminal schedule is considered stuck.',
            (string) self::DEFAULT_MINUTES,
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List stuck schedules without changing them.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $minutes = max(1, (int) $input->getOption('older-than'));
        $dryRun = (bool) $input->getOption('dry-run');
        $deadline = new DateTimeImmutable(\sprintf('-%d minutes', $minutes));

        // Club has no RLS policy → readable with an empty GUC. Collect ids up
        // front so we can clear() the identity map freely while iterating.
        $clubIds = array_map(
            static fn (Club $club): string => $club->getId(),
            $this->entityManager->getRepository(Club::class)->findAll(),
        );
        $this->entityManager->clear();

        $reconciled = 0;
        foreach ($clubIds as $clubId) {
            $reconciled += $this->reconcileClub($clubId, $deadline, $dryRun, $io);
        }

        $io->success(\sprintf(
            '%d stuck schedule(s) %s.',
            $reconciled,
            $dryRun ? 'detected (dry-run)' : 'marked FAILED',
        ));

        return Command::SUCCESS;
    }

    private function reconcileClub(string $clubId, DateTimeImmutable $deadline, bool $dryRun, SymfonyStyle $io): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        try {
            // GENERATING only: a schedule the worker claimed and started, then
            // abandoned (crash/OOM/kill). PENDING is deliberately excluded — a
            // PENDING row may still be legitimately queued, so failing it would
            // race the worker; a permanently-failed message (lock-exhaustion) is
            // instead terminated by ScheduleGenerationFailureListener, not here.
            /** @var list<Schedule> $stuck */
            $stuck = $this->entityManager->getRepository(Schedule::class)
                ->createQueryBuilder('s')
                ->where('s.status = :generating')
                ->andWhere('s.updatedAt < :deadline')
                ->setParameter('generating', ScheduleStatus::GENERATING)
                ->setParameter('deadline', $deadline)
                ->getQuery()
                ->getResult();

            if ($dryRun) {
                foreach ($stuck as $schedule) {
                    $io->writeln(\sprintf('  <comment>would fail</comment> schedule %s (club %s)', $schedule->getId(), $clubId));
                }

                return \count($stuck);
            }

            $failedIds = [];
            foreach ($stuck as $schedule) {
                $schedule->setStatus(ScheduleStatus::FAILED);
                $this->entityManager->persist(
                    (new ScheduleDiagnostic)
                        ->setClubId($schedule->getClubId())
                        ->setSeasonId($schedule->getSeasonId())
                        ->setScheduleId($schedule->getId())
                        ->setType('stuck_timeout')
                        ->setSeverity(ScheduleDiagnosticSeverity::ERROR)
                        ->setMessage('Generation did not finish and was marked failed (worker unavailable or message lost). Regenerate to try again.')
                        ->setSuggestions([]),
                );
                $failedIds[] = $schedule->getId();
            }

            if ([] === $failedIds) {
                return 0;
            }

            // Commit the FAILED status BEFORE notifying: never tell the frontend
            // a schedule failed while the DB still says otherwise.
            $this->entityManager->flush();
            foreach ($failedIds as $scheduleId) {
                $this->publishFailure($clubId, $scheduleId);
            }

            return \count($failedIds);
        } finally {
            $this->entityManager->clear();
            $this->tenantConnectionContext->clear();
        }
    }

    private function publishFailure(string $clubId, string $scheduleId): void
    {
        $this->hub->publish(new Update(
            \sprintf('club:%s:schedule:%s', $clubId, $scheduleId),
            json_encode(['status' => 'failed', 'error' => 'stuck_timeout'], \JSON_THROW_ON_ERROR),
            private: true,
        ));
    }
}
