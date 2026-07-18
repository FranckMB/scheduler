<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * « Un backup jamais restauré n'existe pas » : restaure le dump le plus récent
 * (ou --file) dans une base JETABLE, vérifie qu'elle est lisible (nombre de
 * tables + un SELECT métier), puis la détruit. À lancer après tout changement
 * d'infra et périodiquement (runbook docs/ops/backup-restore.md).
 */
#[AsCommand(
    name: 'app:db:restore-check',
    description: 'Restore the latest dump into a throwaway database and sanity-check it (proof the backup exists).',
)]
final class DatabaseRestoreCheckCommand extends Command
{
    private const MIN_TABLES = 20;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/backups')]
        private readonly string $defaultBackupDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Dump file to check (default: latest in the backup dir).');
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Backup directory (default: var/backups).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $this->resolveDumpFile($input);
        if (null === $file) {
            $io->error('No dump file found — run app:db:backup first.');

            return Command::FAILURE;
        }

        // Nom jetable aléatoire : deux checks concurrents ne se marchent pas dessus.
        // CREATE/DROP via psql (base de maintenance 'postgres'), PAS via Doctrine :
        // CREATE DATABASE refuse de tourner dans une transaction, et la connexion
        // Doctrine peut en porter une (wrapper transactionnel des tests).
        $database = 'clubscheduler_restore_' . bin2hex(random_bytes(4));
        $this->maintenance(\sprintf('CREATE DATABASE %s', $database));

        try {
            $restore = new Process(
                ['pg_restore', '--no-owner', '--no-privileges', '--dbname', $database, '--host', $this->env('POSTGRES_HOST', 'postgres'), '--port', $this->env('POSTGRES_PORT', '5432'), '--username', $this->env('POSTGRES_USER', 'clubscheduler'), $file],
                env: ['PGPASSWORD' => $this->env('POSTGRES_PASSWORD', '')],
                timeout: 900,
            );
            $restore->run();
            if (!$restore->isSuccessful()) {
                $io->error('pg_restore failed: ' . trim($restore->getErrorOutput()));

                return Command::FAILURE;
            }

            // Sanity via psql (la connexion Doctrine pointe la base nominale, pas la jetable).
            $tables = (int) $this->scalar($database, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'public\'');
            $clubs = (int) $this->scalar($database, 'SELECT COUNT(*) FROM club');
            if ($tables < self::MIN_TABLES) {
                $io->error(\sprintf('Restored database has only %d tables (expected >= %d) — dump likely truncated.', $tables, self::MIN_TABLES));

                return Command::FAILURE;
            }

            $io->success(\sprintf('Restore check passed: %s → %d tables, %d club(s). The backup is real.', basename($file), $tables, $clubs));

            return Command::SUCCESS;
        } finally {
            $this->maintenance(\sprintf('DROP DATABASE IF EXISTS %s', $database));
        }
    }

    /** Ordre administratif hors transaction, sur la base de maintenance 'postgres'. */
    private function maintenance(string $sql): void
    {
        $psql = new Process(
            ['psql', '--host', $this->env('POSTGRES_HOST', 'postgres'), '--port', $this->env('POSTGRES_PORT', '5432'), '--username', $this->env('POSTGRES_USER', 'clubscheduler'), '--dbname', 'postgres', '--command', $sql],
            env: ['PGPASSWORD' => $this->env('POSTGRES_PASSWORD', '')],
            timeout: 60,
        );
        $psql->mustRun();
    }

    private function resolveDumpFile(InputInterface $input): ?string
    {
        $fileOption = $input->getOption('file');
        if (\is_string($fileOption) && '' !== $fileOption) {
            return is_file($fileOption) ? $fileOption : null;
        }

        $dirOption = $input->getOption('dir');
        $dir = \is_string($dirOption) && '' !== $dirOption ? $dirOption : $this->defaultBackupDir;
        $files = glob(rtrim($dir, '/') . '/clubscheduler-*.dump') ?: [];
        sort($files);

        return [] === $files ? null : end($files);
    }

    private function scalar(string $database, string $sql): string
    {
        $psql = new Process(
            ['psql', '--tuples-only', '--no-align', '--host', $this->env('POSTGRES_HOST', 'postgres'), '--port', $this->env('POSTGRES_PORT', '5432'), '--username', $this->env('POSTGRES_USER', 'clubscheduler'), '--dbname', $database, '--command', $sql],
            env: ['PGPASSWORD' => $this->env('POSTGRES_PASSWORD', '')],
            timeout: 60,
        );
        $psql->mustRun();

        return trim($psql->getOutput());
    }

    private function env(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
