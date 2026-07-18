<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SA4 — action support : remet le compteur de générations de la saison à zéro
 * (déblocage du quota Découverte). Connexion admin (bypass RLS, porte superadmin) :
 * l'action est cross-tenant par conception et cible UN club explicitement nommé.
 */
#[AsCommand(
    name: 'app:clubs:reset-quota',
    description: 'Reset a club\'s generation counter to zero (Découverte quota unblock). Support action (SA4).',
)]
final class ResetClubQuotaCommand extends Command
{
    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('club', null, InputOption::VALUE_REQUIRED, 'Target club id (required).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clubId = $input->getOption('club');
        if (!\is_string($clubId) || '' === $clubId) {
            $io->error('--club <id> is required.');

            return Command::FAILURE;
        }

        $updated = $this->connection()->executeStatement(
            'UPDATE club SET generation_count_season = 0 WHERE id = :id',
            ['id' => $clubId],
        );
        if (0 === $updated) {
            $io->error(\sprintf('Club %s not found.', $clubId));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Generation counter reset to 0 for club %s.', $clubId));

        return Command::SUCCESS;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
