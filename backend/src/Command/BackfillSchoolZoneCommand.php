<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Service\SchoolZoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills Club.schoolZone from the FFBB code for clubs registered before the
 * zone-derivation existed. Dry-run by default (prints what would change);
 * --apply persists. Never overwrites a zone already set (manual entry wins).
 *
 * The club table has no club_id / RLS, so it is walkable on the runtime
 * connection with an empty GUC (same as app:schedules:reconcile-stuck).
 */
#[AsCommand(
    name: 'app:clubs:backfill-school-zone',
    description: 'Derive Club.schoolZone from the FFBB code for clubs missing it (dry-run unless --apply).',
)]
final class BackfillSchoolZoneCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolZoneResolver $schoolZoneResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the changes (default: dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        /** @var list<Club> $clubs */
        $clubs = $this->entityManager->getRepository(Club::class)->findBy(['schoolZone' => null]);

        $resolved = 0;
        $undecidable = 0;
        $rows = [];
        foreach ($clubs as $club) {
            $code = $club->getFfbbClubCode();
            if (null === $code || '' === $code) {
                continue;
            }
            $zone = $this->schoolZoneResolver->resolveFromFfbbCode($code);
            if (null === $zone) {
                ++$undecidable;

                continue;
            }
            $rows[] = [$club->getName(), $code, $zone];
            if ($apply) {
                $club->setSchoolZone($zone);
            }
            ++$resolved;
        }

        if ([] !== $rows) {
            $io->table(['Club', 'FFBB code', 'Zone'], $rows);
        }

        if ($apply) {
            $this->entityManager->flush();
            $io->success(\sprintf('%d club(s) updated, %d undecidable (left null).', $resolved, $undecidable));
        } else {
            $io->note(\sprintf('Dry-run: %d club(s) would be updated, %d undecidable. Re-run with --apply.', $resolved, $undecidable));
        }

        return Command::SUCCESS;
    }
}
