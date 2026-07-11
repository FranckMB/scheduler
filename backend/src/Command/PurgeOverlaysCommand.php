<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Repository\CalendarEntryRepository;
use App\Service\OverlayManager;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * planning-versions (overlay versions): delete every overlay version of a period
 * whose window has already ended (endDate < today) — the workspace cleanup for
 * past periods. Manual command today; wired to a cron later via the superadmin
 * console (like app:seasons:purge — no cron-runner added here). Walks clubs on
 * the runtime (RLS) connection, each under its own GUC; a failure on one period
 * or club never blocks the others. NEVER touches season plans nor a period still
 * active/upcoming.
 */
#[AsCommand(
    name: 'app:overlays:purge',
    description: 'Delete overlay versions of periods whose endDate has passed. Manual, never auto-runs.',
)]
final class PurgeOverlaysCommand extends Command
{
    private bool $hadFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly OverlayManager $overlayManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged without deleting anything.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Restrict to a single club id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->hadFailure = false;
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        if (\is_string($dateOption) && '' !== $dateOption) {
            $today = $this->parseDate($dateOption);
            if (!$today instanceof DateTimeImmutable) {
                $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

                return Command::FAILURE;
            }
        } else {
            $today = new DateTimeImmutable('today');
        }

        $clubFilter = $input->getOption('club');
        $repository = $this->entityManager->getRepository(Club::class);
        $clubs = \is_string($clubFilter) && '' !== $clubFilter
            ? array_filter([$repository->find($clubFilter)])
            : $repository->findAll();
        $this->entityManager->clear();

        $purgedTotal = 0;
        foreach ($clubs as $club) {
            try {
                $purgedTotal += $this->purgeClub($club->getId(), $today, $dryRun, $io);
            } catch (Throwable $e) {
                $this->hadFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d overlay version(s) %s.', $purgedTotal, $dryRun ? 'would be purged (dry-run)' : 'purged'));

        return $this->hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function purgeClub(string $clubId, DateTimeImmutable $today, bool $dryRun, SymfonyStyle $io): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        $purged = 0;
        foreach ($this->calendarEntryRepository->findEndedPeriods($clubId, $today) as $entry) {
            if ($dryRun) {
                // Dry-run only counts (the real path derives the total from the
                // deletion itself, avoiding a second round-trip over the same rows).
                $count = $this->entityManager->getRepository(Schedule::class)->count(['calendarEntryId' => $entry->getId()]);
                if ($count > 0) {
                    $io->writeln($this->line('<comment>would</comment>', $count, $entry, $clubId));
                    $purged += $count;
                }

                continue;
            }
            try {
                // force: an ended period is being cleaned up; a validated version
                // is fair game (this is the authorized purge path).
                $removed = $this->overlayManager->deleteOverlayForEntry($entry, force: true);
            } catch (Throwable $e) {
                $this->hadFailure = true;
                $io->warning(\sprintf('  period %s skipped: %s', $entry->getId(), $e->getMessage()));

                continue;
            }
            if ($removed > 0) {
                $io->writeln($this->line('<info>✓</info>', $removed, $entry, $clubId));
                $purged += $removed;
            }
        }

        return $purged;
    }

    private function line(string $mark, int $count, CalendarEntry $entry, string $clubId): string
    {
        return \sprintf(
            '  %s purge %d overlay version(s) of period "%s" (%s, ended %s, club %s)',
            $mark,
            $count,
            $entry->getTitle(),
            $entry->getId(),
            $entry->getEndDate()->format('Y-m-d'),
            $clubId,
        );
    }

    /** Strict: a real calendar date, else null. */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
