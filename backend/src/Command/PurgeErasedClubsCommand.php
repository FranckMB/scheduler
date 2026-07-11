<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Service\ErasedClubPurger;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * RGPD — exécute la purge des workspaces dont le délai de grâce est échu
 * (Club.erasureScheduledAt <= maintenant, posé par AccountErasureService quand
 * le dernier gestionnaire s'est effacé). Tourne au cron-runner (horaire) ;
 * chaque club sous son propre GUC, un échec n'en bloque pas un autre (pattern
 * PurgeSeasonsCommand). L'identité publique FFBB survit (ErasedClubPurger).
 */
#[AsCommand(
    name: 'app:clubs:purge-erased',
    description: 'Purge the workspace of clubs whose erasure grace period has elapsed (RGPD). FFBB identity survives.',
)]
final class PurgeErasedClubsCommand extends Command
{
    private bool $hadFailure = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly ErasedClubPurger $erasedClubPurger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be purged without deleting anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->hadFailure = false;
        $dryRun = (bool) $input->getOption('dry-run');
        $now = DateTimeImmutable::createFromInterface($this->clock->now());

        $due = $this->entityManager->getRepository(Club::class)->createQueryBuilder('c')
            ->where('c.erasureScheduledAt IS NOT NULL')
            ->andWhere('c.erasureScheduledAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
        $this->entityManager->clear();

        $purged = 0;
        foreach ($due as $club) {
            \assert($club instanceof Club);
            $clubId = $club->getId();
            try {
                $io->writeln(\sprintf('  %s purge workspace of club %s (%s, scheduled %s)', $dryRun ? '<comment>would</comment>' : '<info>✓</info>', $club->getName(), $clubId, $club->getErasureScheduledAt()?->format('Y-m-d') ?? '?'));
                if (!$dryRun) {
                    $this->tenantConnectionContext->setClubId($clubId);
                    $freshClub = $this->entityManager->getRepository(Club::class)->find($clubId);
                    if ($freshClub instanceof Club) {
                        $this->erasedClubPurger->purge($freshClub);
                    }
                }
                ++$purged;
            } catch (Throwable $e) {
                $this->hadFailure = true;
                $io->warning(\sprintf('Club %s skipped: %s', $clubId, $e->getMessage()));
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }

        $io->success(\sprintf('%d club workspace(s) %s.', $purged, $dryRun ? 'would be purged (dry-run)' : 'purged'));

        return $this->hadFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
