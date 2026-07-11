<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\AccountErasureService;
use App\Service\InactivityMailBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Throwable;

/**
 * RGPD — rétention des comptes (politique : inactifs 2 ans).
 *
 * Inactivité = COALESCE(lastLoginAt, createdAt). Deux étages :
 * - 23 mois → email de PRÉAVIS (inactivityWarnedAt posé, annulé par un login) ;
 * - 24 mois ET préavis envoyé depuis ≥ 14 j → ANONYMISATION via
 *   AccountErasureService (même routine que DELETE /api/me : memberships
 *   désactivés, club orphelin programmé à +30 j).
 * Le garde-fou « préavis ≥ 14 j » garantit qu'un compte n'est JAMAIS effacé
 * sans avoir été prévenu, même si le cron est resté down des semaines.
 *
 * Tourne au cron-runner (horaire). Horloge applicative (SimulatedClock en dev).
 */
#[AsCommand(
    name: 'app:users:purge-inactive',
    description: 'RGPD retention: warn accounts inactive 23 months, anonymize at 24 months (warning ≥14 days old required).',
)]
final class PurgeInactiveUsersCommand extends Command
{
    private const WARN_AFTER = '-23 months';
    private const ERASE_AFTER = '-24 months';
    private const MIN_WARNING_AGE = '-14 days';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly AccountErasureService $accountErasureService,
        private readonly InactivityMailBuilder $mailBuilder,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List warnings/erasures without sending or deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        [$warned, $warnFailed] = $this->warn($io, $now, $dryRun);
        [$erased, $eraseFailed] = $this->erase($io, $now, $dryRun);

        $io->success(\sprintf(
            '%d warning(s), %d anonymization(s)%s.',
            $warned,
            $erased,
            $dryRun ? ' (dry-run)' : '',
        ));

        return ($warnFailed || $eraseFailed) ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array{int, bool} [processed, hadFailure] */
    private function warn(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): array
    {
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.anonymizedAt IS NULL')
            ->andWhere('u.inactivityWarnedAt IS NULL')
            ->andWhere('COALESCE(u.lastLoginAt, u.createdAt) < :threshold')
            ->setParameter('threshold', $now->modify(self::WARN_AFTER))
            ->getQuery()
            ->getResult();

        $count = 0;
        $failed = false;
        foreach ($users as $user) {
            \assert($user instanceof User);
            $io->writeln(\sprintf('  %s warn %s (inactive since %s)', $dryRun ? '<comment>would</comment>' : '<info>✉</info>', $user->getEmail(), ($user->getLastLoginAt() ?? $user->getCreatedAt())->format('Y-m-d')));
            if (!$dryRun) {
                try {
                    $this->mailer->send($this->mailBuilder->build($user->getEmail(), $user->getFirstName()));
                    $user->setInactivityWarnedAt($now);
                    $this->entityManager->flush();
                } catch (Throwable $e) {
                    // Échec d'envoi → PAS de warnedAt (sinon l'anonymisation
                    // partirait sans que le préavis ait réellement été émis).
                    $failed = true;
                    $io->warning(\sprintf('Warning to %s failed: %s', $user->getEmail(), $e->getMessage()));
                }
            }
            ++$count;
        }

        return [$count, $failed];
    }

    /** @return array{int, bool} [processed, hadFailure] */
    private function erase(SymfonyStyle $io, DateTimeImmutable $now, bool $dryRun): array
    {
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.anonymizedAt IS NULL')
            ->andWhere('COALESCE(u.lastLoginAt, u.createdAt) < :threshold')
            ->andWhere('u.inactivityWarnedAt IS NOT NULL')
            ->andWhere('u.inactivityWarnedAt < :minWarningAge')
            ->setParameter('threshold', $now->modify(self::ERASE_AFTER))
            ->setParameter('minWarningAge', $now->modify(self::MIN_WARNING_AGE))
            ->getQuery()
            ->getResult();
        // Ids d'abord : après un resetManager (échec précédent), les entités
        // déjà hydratées seraient détachées — chaque itération refetch frais.
        $userIds = array_map(static fn (User $u): string => $u->getId(), $users);
        $this->entityManager->clear();

        $count = 0;
        $failed = false;
        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, $userId);
            if (!$user instanceof User) {
                continue;
            }
            $io->writeln(\sprintf('  %s anonymize %s (warned %s)', $dryRun ? '<comment>would</comment>' : '<info>✓</info>', $user->getEmail(), $user->getInactivityWarnedAt()?->format('Y-m-d') ?? '?'));
            if (!$dryRun) {
                try {
                    $this->accountErasureService->erase($user);
                } catch (Throwable $e) {
                    $failed = true;
                    $io->warning(\sprintf('Erasure of %s failed: %s', $userId, $e->getMessage()));
                    // Un flush raté FERME l'EntityManager (leçon PR-1) : sans
                    // reset, tous les users suivants échoueraient en cascade.
                    if (!$this->entityManager->isOpen()) {
                        $this->managerRegistry->resetManager();
                        $manager = $this->managerRegistry->getManager();
                        \assert($manager instanceof EntityManagerInterface);
                        $this->entityManager = $manager;
                    }
                    continue;
                }
            }
            ++$count;
        }

        return [$count, $failed];
    }
}
