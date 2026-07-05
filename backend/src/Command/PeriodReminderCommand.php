<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Season;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubUserRepository;
use App\Repository\SeasonRepository;
use App\Service\PeriodReminderMailBuilder;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
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
 * Daily reminder cron (cockpit palier C, v3 §8.2). Emails a club's managers when
 * a period (closure/holiday/…) is approaching and still has NO overlay plan, at
 * exactly J-14, J-7 and J-3 before it starts. It NEVER acts on its own — the
 * radar surfaces the same to-dos in-app; this is the out-of-app nudge.
 *
 * Walks clubs on the runtime (RLS) connection like ReconcileStuckSchedulesCommand
 * (club has no RLS policy → readable with empty GUC; each club's data read under
 * its own GUC). A failure on one club never blocks the others.
 */
#[AsCommand(
    name: 'app:periods:remind',
    description: 'Email club managers about upcoming periods still lacking an overlay plan (J-14/J-7/J-3; never auto-acts).',
)]
final class PeriodReminderCommand extends Command
{
    /** Days-before-start thresholds that trigger a reminder. J-3 is the "red" one. */
    private const THRESHOLDS = [14, 7, 3];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly MailerInterface $mailer,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly PeriodReminderMailBuilder $mailBuilder,
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
        $today = $this->resolveToday($input->getOption('date'));

        $clubIds = array_map(
            static fn (Club $club): string => $club->getId(),
            $this->entityManager->getRepository(Club::class)->findAll(),
        );
        $this->entityManager->clear();

        $sent = 0;
        foreach ($clubIds as $clubId) {
            try {
                $sent += $this->remindClub($clubId, $today, $dryRun, $io);
            } catch (Throwable $e) {
                // One bad club must not block reminders for the others.
                $io->warning(\sprintf('Club %s skipped: %s', $clubId, $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d reminder email(s) %s.', $sent, $dryRun ? 'detected (dry-run)' : 'sent'));

        return Command::SUCCESS;
    }

    private function remindClub(string $clubId, DateTimeImmutable $today, bool $dryRun, SymfonyStyle $io): int
    {
        $this->tenantConnectionContext->setClubId($clubId);

        $season = $this->seasonRepository->findActiveByClubId($clubId);
        if (!$season instanceof Season) {
            return 0;
        }

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return 0;
        }

        $emails = $this->clubUserRepository->findManagementEmails($clubId);

        $sent = 0;
        foreach (self::THRESHOLDS as $days) {
            $targetDate = $today->modify(\sprintf('+%d days', $days));
            foreach ($this->calendarEntryRepository->findPeriodsWithoutOverlayStartingOn($clubId, $season->getId(), $targetDate) as $entry) {
                if ($dryRun) {
                    $io->writeln(\sprintf('  <comment>would remind</comment> J-%d · %s (club %s) → %d manager(s)', $days, $entry->getTitle(), $clubId, \count($emails)));
                    $sent += \count($emails);

                    continue;
                }
                foreach ($emails as $to) {
                    try {
                        $this->mailer->send($this->mailBuilder->build($to, $club->getName(), $entry, $days));
                        ++$sent;
                    } catch (Throwable $e) {
                        $io->warning(\sprintf('Email to %s failed: %s', $to, $e->getMessage()));
                    }
                }
            }
        }

        return $sent;
    }

    private function resolveToday(mixed $dateOption): DateTimeImmutable
    {
        $raw = \is_string($dateOption) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOption) ? $dateOption : 'today';

        return new DateTimeImmutable($raw . ' 00:00:00');
    }
}
