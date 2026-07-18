<?php

declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sauvegarde `pg_dump -Fc` PILOTÉE PAR L'ACTIVITÉ (décision fondateur 2026-07-18) :
 * le job tourne chaque nuit (tick pas cher) mais ne dumpe QUE s'il y a eu de
 * l'activité depuis le dernier dump — zéro club = zéro dump, août-octobre =
 * quotidien de fait. RPO réel : au pire la journée d'activité en cours, quelle
 * que soit la saison. Rétention : les 14 dumps les plus récents.
 *
 * Ceci est la couche « restauration fine » (corruption logique, migration ratée,
 * mauvais club purgé) — la couche « disque mort » est le snapshot de l'hébergeur
 * (checklist docs/ops/backup-restore.md). Hook off-site optionnel via
 * BACKUP_SYNC_COMMAND (rclone/rsync au choix de l'op), jamais bloquant.
 */
#[AsCommand(
    name: 'app:db:backup',
    description: 'Activity-driven pg_dump backup (skips when nothing changed). Retention: 14 dumps. Runs nightly (cron-runner).',
)]
final class DatabaseBackupCommand extends Command
{
    private const RETENTION = 14;

    public function __construct(
        private readonly ManagerRegistry $registry,
        #[Autowire('%kernel.project_dir%/var/backups')]
        private readonly string $defaultBackupDir,
        #[Autowire('%env(default::BACKUP_SYNC_COMMAND)%')]
        private readonly ?string $syncCommand = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Dump even without new activity.');
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Override the backup directory (tests/ops).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dirOption = $input->getOption('dir');
        $dir = \is_string($dirOption) && '' !== $dirOption ? $dirOption : $this->defaultBackupDir;
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->error(\sprintf('Cannot create backup directory %s.', $dir));

            return Command::FAILURE;
        }

        $lastDumpAt = $this->latestDumpTime($dir);
        $lastActivityAt = $this->latestActivity();
        $io->writeln(\sprintf('activity=%s lastDump=%s', $lastActivityAt?->format('Y-m-d H:i:s.u') ?? 'none', $lastDumpAt?->format('Y-m-d H:i:s.u') ?? 'none'), OutputInterface::VERBOSITY_VERBOSE);

        if (!(bool) $input->getOption('force')) {
            if (null === $lastActivityAt) {
                $io->writeln('No activity at all — nothing to protect, skipping.');

                return Command::SUCCESS;
            }
            // Tolérance d'UNE seconde : les colonnes d'activité sont TIMESTAMP(0) — Postgres
            // ARRONDIT à la seconde (07.884 → 08) quand filemtime TRONQUE (07.88 → 07). Sans
            // marge, une activité contenue DANS le dump paraît postérieure et re-dumpe en
            // boucle. Une vraie activité > 1 s après le dump reste détectée.
            if (null !== $lastDumpAt && (int) $lastDumpAt->format('U') + 1 >= (int) $lastActivityAt->format('U')) {
                $io->writeln(\sprintf('No activity since last dump (%s) — skipping.', $lastDumpAt->format('Y-m-d H:i')));

                return Command::SUCCESS;
            }
        }

        // Microsecondes dans le nom : deux dumps dans la même seconde (--force enchaîné,
        // tests) ne doivent jamais s'écraser. L'ordre lexical reste l'ordre chronologique.
        $file = \sprintf('%s/clubscheduler-%s.dump', rtrim($dir, '/'), new DateTimeImmutable()->format('Ymd-His-u'));
        $process = new Process(
            ['pg_dump', '--format=custom', '--file', $file, '--host', $this->env('POSTGRES_HOST', 'postgres'), '--port', $this->env('POSTGRES_PORT', '5432'), '--username', $this->env('POSTGRES_USER', 'clubscheduler'), $this->env('POSTGRES_DB', 'clubscheduler')],
            env: ['PGPASSWORD' => $this->env('POSTGRES_PASSWORD', '')],
            timeout: 600,
        );
        $process->run();
        if (!$process->isSuccessful()) {
            @unlink($file);
            // stderr de pg_dump : diagnostic sans secret (PGPASSWORD passe par l'env, pas argv).
            $io->error('pg_dump failed: ' . trim($process->getErrorOutput()));

            return Command::FAILURE;
        }

        $size = filesize($file);
        $io->success(\sprintf('Dump written: %s (%s).', basename($file), false === $size ? '?' : $this->humanSize($size)));

        $this->applyRetention($dir, $io);
        $this->runSyncHook($io);

        return Command::SUCCESS;
    }

    /**
     * Le signal « quelque chose a bougé » : activité authentifiée (Club.lastActivityAt,
     * déjà alimenté), tentatives solveur (append-only) et gestes audités. Connexion
     * admin : lecture cross-tenant sans GUC.
     */
    private function latestActivity(): ?DateTimeImmutable
    {
        $value = $this->admin()->fetchOne(
            'SELECT GREATEST(
                (SELECT MAX(last_activity_at) FROM club),
                (SELECT MAX(created_at) FROM solver_metrics),
                (SELECT MAX(occurred_at) FROM audit_log)
            )',
        );

        return \is_string($value) ? new DateTimeImmutable($value) : null;
    }

    private function latestDumpTime(string $dir): ?DateTimeImmutable
    {
        $latest = null;
        foreach (glob(rtrim($dir, '/') . '/clubscheduler-*.dump') ?: [] as $file) {
            $mtime = filemtime($file);
            if (false !== $mtime && (null === $latest || $mtime > $latest)) {
                $latest = $mtime;
            }
        }

        return null === $latest ? null : new DateTimeImmutable('@' . $latest);
    }

    private function applyRetention(string $dir, SymfonyStyle $io): void
    {
        $files = glob(rtrim($dir, '/') . '/clubscheduler-*.dump') ?: [];
        // Le nom porte l'horodatage → l'ordre lexical EST l'ordre chronologique.
        sort($files);
        $excess = \count($files) - self::RETENTION;
        for ($i = 0; $i < $excess; ++$i) {
            @unlink($files[$i]);
            $io->writeln(\sprintf('Retention: removed %s.', basename($files[$i])));
        }
    }

    /** Off-site optionnel : commande shell de l'op (rclone/rsync). Échec = WARNING, jamais un échec du dump. */
    private function runSyncHook(SymfonyStyle $io): void
    {
        if (null === $this->syncCommand || '' === $this->syncCommand) {
            return;
        }

        try {
            $sync = Process::fromShellCommandline($this->syncCommand, timeout: 900);
            $sync->run();
            if (!$sync->isSuccessful()) {
                $io->warning('Off-site sync failed (dump kept locally): ' . trim($sync->getErrorOutput()));
            } else {
                $io->writeln('Off-site sync done.');
            }
        } catch (Throwable $e) {
            $io->warning('Off-site sync errored (dump kept locally): ' . $e->getMessage());
        }
    }

    private function env(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1_048_576 ? \sprintf('%.1f MiB', $bytes / 1_048_576) : \sprintf('%.1f KiB', $bytes / 1024);
    }

    private function admin(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
