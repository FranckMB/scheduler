<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes accounts that registered but never verified their email within the TTL
 * (7 days). Registration is deferred, so an unverified User has NO club/membership
 * (the tenant is only materialised on verify) — deletion is a plain row drop, and
 * its EmailVerificationToken rows cascade (onDelete CASCADE). Manual/cron; never
 * auto-runs. User has no club_id → no RLS policy → no tenant GUC needed.
 */
#[AsCommand(
    name: 'app:users:purge-unverified',
    description: 'Delete accounts left unverified past the 7-day TTL (+ their verification tokens). Manual, never auto-runs.',
)]
final class PurgeUnverifiedUsersCommand extends Command
{
    private const TTL_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged without deleting anything.');
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Treat this YYYY-MM-DD as "today" (rehearsal/tests).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dateOption = $input->getOption('date');
        if (\is_string($dateOption) && '' !== $dateOption) {
            $today = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption);
            if (false === $today) {
                $io->error('Invalid --date: expected a real calendar date YYYY-MM-DD.');

                return Command::FAILURE;
            }
        } else {
            $today = new DateTimeImmutable;
        }
        $threshold = $today->modify(\sprintf('-%d days', self::TTL_DAYS));

        /** @var list<User> $stale */
        $stale = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.emailVerifiedAt IS NULL')
            ->andWhere('u.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        if ([] === $stale) {
            $io->success('No unverified accounts past the TTL.');

            return Command::SUCCESS;
        }

        foreach ($stale as $user) {
            $io->writeln(\sprintf('%s (registered %s)', $user->getEmail(), $user->getCreatedAt()->format('Y-m-d')));
            if (!$dryRun) {
                $this->entityManager->remove($user);
            }
        }

        if ($dryRun) {
            $io->note(\sprintf('%d unverified account(s) would be purged (dry-run).', \count($stale)));

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(\sprintf('Purged %d unverified account(s).', \count($stale)));

        return Command::SUCCESS;
    }
}
