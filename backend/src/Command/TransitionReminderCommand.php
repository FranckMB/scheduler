<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\TransitionReminderLog;
use App\Repository\ClubUserRepository;
use App\Repository\TransitionReminderLogRepository;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Service\TransitionReminderMailBuilder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * Anticipation reminder cron (transition de saison P2-PR2). Emails a club's
 * managers when the July-15 season pivot is approaching and season N+1 has NOT
 * been prepared yet, at ~May 15 (J-61), ~June 15 (J-30) and ~July 1 (J-14).
 * It NEVER acts on its own — the in-app banner surfaces the same nudge; this
 * is the out-of-app reminder. Window is [May 15, July 15[ — the pivot day
 * itself is excluded (the switch has happened).
 *
 * Walks clubs on the runtime (RLS) connection like PeriodReminderCommand
 * (club has no RLS policy → readable with empty GUC; each club's data read
 * under its own GUC). A failure on one club never blocks the others.
 */
#[AsCommand(
    name: 'app:seasons:remind-transition',
    description: 'Email club managers who have not prepared season N+1 yet (J-61/J-30/J-14 before the July-15 pivot).',
)]
final class TransitionReminderCommand extends Command
{
    /** Milestone buckets (days-before-pivot). May 15 = J-61 exactly; J-14 is "red". */
    private const HORIZON = 61;

    private bool $hadSendFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly MailerInterface $mailer,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly TransitionReminderLogRepository $reminderLogRepository,
        private readonly TransitionReminderMailBuilder $mailBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List reminders without sending any email.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        $forcedToday = $this->resolveToday($dateOption);
        if (\is_string($dateOption) && '' !== $dateOption && !$forcedToday instanceof DateTimeImmutable) {
            $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

            return Command::FAILURE;
        }

        // Detached after clear(), but only id/name/timezone getters are read —
        // no per-club re-SELECT inside the loop.
        $clubs = $this->entityManager->getRepository(Club::class)->findAll();
        $this->entityManager->clear();

        $sent = 0;
        foreach ($clubs as $club) {
            try {
                $sent += $this->remindClub($club, $forcedToday, $dryRun, $io);
            } catch (Throwable $e) {
                // One bad club must not block reminders for the others.
                $io->warning(\sprintf('Club %s skipped: %s', $club->getId(), $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d transition reminder email(s) %s.', $sent, $dryRun ? 'detected (dry-run)' : 'sent'));

        // Exit non-zero if any send failed so a cron monitor sees the outage
        // (a total mailer failure must NOT look like a healthy run).
        return $this->hadSendFailure ? Command::FAILURE : Command::SUCCESS;
    }

    private function remindClub(Club $club, ?DateTimeImmutable $forcedToday, bool $dryRun, SymfonyStyle $io): int
    {
        $clubId = $club->getId();
        $this->tenantConnectionContext->setClubId($clubId);

        // "Today" is the CLUB's calendar day, not the server's. An explicit
        // --date (rehearsal/tests) overrides for all clubs.
        $today = $forcedToday ?? $this->clubToday($club);

        $seasons = $this->seasonResolver->seasonsForClub($clubId);
        if ([] === $seasons) {
            return 0;
        }
        $current = SeasonResolver::currentAmong($seasons, $today);
        if (!$current instanceof Season) {
            return 0;
        }

        // Already prepared: any season binned AFTER the current one silences
        // the reminder (mail and banner alike).
        $currentYear = SeasonResolver::seasonYear($current->getStartDate());
        foreach ($seasons as $season) {
            if (SeasonResolver::seasonYear($season->getStartDate()) > $currentYear) {
                return 0;
            }
        }

        $pivot = new DateTimeImmutable(\sprintf('%d-07-15', $currentYear + 1));
        $days = (int) $today->diff($pivot)->format('%r%a');
        $threshold = $this->bucket($days);
        if (null === $threshold || $this->reminderLogRepository->alreadySent($current->getId(), $threshold)) {
            return 0; // outside the window/buckets, or this milestone already emailed.
        }

        $emails = $this->clubUserRepository->findManagementEmails($clubId);

        if ($dryRun) {
            $io->writeln(\sprintf('  <comment>would remind</comment> J-%d (bucket %d) · saison %s (club %s) → %d manager(s)', $days, $threshold, $current->getName(), $clubId, \count($emails)));

            return \count($emails);
        }

        $sentForSeason = 0;
        foreach ($emails as $to) {
            try {
                $this->mailer->send($this->mailBuilder->build($to, $club->getName(), $current->getName(), $pivot, $days));
                ++$sentForSeason;
            } catch (Throwable $e) {
                $this->hadSendFailure = true;
                $io->warning(\sprintf('Email to %s failed: %s', $to, $e->getMessage()));
            }
        }

        // Mark the milestone sent only if it actually reached someone, so a
        // failed run retries it next time instead of losing it.
        if ($sentForSeason > 0) {
            $this->markSent($current, $threshold);
        }

        return $sentForSeason;
    }

    private function markSent(Season $season, int $threshold): void
    {
        $this->entityManager->persist(
            (new TransitionReminderLog)->setSeasonId($season->getId())->setThreshold($threshold),
        );
        $this->entityManager->flush();
    }

    /**
     * The milestone bucket "today" is in, by days before the July-15 pivot:
     * 61 (>30..61d ≈ from May 15), 30 (>14..30d ≈ from June 15), 14 (1..14d ≈
     * from July 1), null otherwise. days < 1 excludes the pivot day itself.
     */
    private function bucket(int $days): ?int
    {
        if ($days < 1 || $days > self::HORIZON) {
            return null;
        }
        if ($days <= 14) {
            return 14;
        }

        return $days <= 30 ? 30 : 61;
    }

    /**
     * The club's current calendar day, materialized as a plain (server-TZ) date so
     * diffs against the date-only pivot stay whole days.
     */
    private function clubToday(Club $club): DateTimeImmutable
    {
        $timezone = 'Europe/Paris';
        if ('' !== $club->getTimezone()) {
            try {
                new DateTimeZone($club->getTimezone());
                $timezone = $club->getTimezone();
            } catch (Throwable) {
                // Invalid stored TZ → keep the FFBB default.
            }
        }

        return new DateTimeImmutable(new DateTimeImmutable('now', new DateTimeZone($timezone))->format('Y-m-d'));
    }

    /** Strict: a real calendar date, else null (rejects rollovers like 2026-02-30). No --date → null (per-club today). */
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
