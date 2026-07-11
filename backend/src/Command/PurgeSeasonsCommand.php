<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Service\SeasonDataPurger;
use App\Service\SeasonResolver;
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
 * Retention purge (spec transition-de-saison §3): keep a sliding window of two
 * seasons — the CURRENT one and its immediate predecessor (N-1, read-only) —
 * plus any future draft. Everything older (N-2 and beyond) is deleted, Season
 * row included. AUTOMATED since RGPD PR-3 (cron-runner, hourly): the retention
 * policy must be effectively enforced, not merely available (P0-1).
 *
 * Walks clubs on the runtime (RLS) connection like PeriodReminderCommand —
 * each club under its own GUC, a failure on one never blocks the others.
 */
#[AsCommand(
    name: 'app:seasons:purge',
    description: 'Delete seasons older than N-1 (retention: current + predecessor + futures kept). Runs hourly (cron-runner, RGPD).',
)]
final class PurgeSeasonsCommand extends Command
{
    private bool $hadFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly SeasonResolver $seasonResolver,
        private readonly SeasonDataPurger $seasonDataPurger,
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
        $today = $this->resolveToday($dateOption);
        if (\is_string($dateOption) && '' !== $dateOption && !$today instanceof DateTimeImmutable) {
            $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

            return Command::FAILURE;
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

        $io->success(\sprintf('%d season(s) %s.', $purgedTotal, $dryRun ? 'would be purged (dry-run)' : 'purged'));

        return $this->hadFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function purgeClub(string $clubId, ?DateTimeImmutable $today, bool $dryRun, SymfonyStyle $io): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        $seasons = $this->seasonResolver->seasonsForClub($clubId);
        $current = SeasonResolver::currentAmong($seasons, $today);
        if (null === $current) {
            return 0;
        }

        $currentYear = SeasonResolver::seasonYear($current->getStartDate());
        // Keep: current, its immediate predecessor (currentYear - 1), and any
        // future (>= currentYear) draft. Purge strictly older than N-1.
        $purged = 0;
        foreach ($seasons as $season) {
            if (SeasonResolver::seasonYear($season->getStartDate()) >= $currentYear - 1) {
                continue;
            }
            $io->writeln(\sprintf('  %s purge season %s (%s, club %s)', $dryRun ? '<comment>would</comment>' : '<info>✓</info>', $season->getName(), $season->getId(), $clubId));
            if (!$dryRun) {
                $this->seasonDataPurger->purge($clubId, $season->getId(), deleteSeasonRow: true);
            }
            ++$purged;
        }

        return $purged;
    }

    /** Strict: a real calendar date, else null. No --date → null (calendar-derived per club). */
    private function resolveToday(mixed $dateOption): ?DateTimeImmutable
    {
        if (!\is_string($dateOption) || '' === $dateOption) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
