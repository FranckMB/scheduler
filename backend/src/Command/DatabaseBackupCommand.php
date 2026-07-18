<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BackupCoverage;
use DateTimeImmutable;
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
 * Robustesse (revue #258) :
 * - écriture vers un `.part` puis RENAME atomique — un pg_dump interrompu (timeout,
 *   kill) ne peut JAMAIS devenir « le dernier dump » et supprimer les suivants ;
 * - le mtime du dump est posé au DÉBUT du snapshot (pg_dump photographie au start,
 *   pas à la fin) — une écriture pendant un dump long reste détectée comme non couverte ;
 * - BOOTSTRAP : une base qui contient des données mais aucun signal d'activité
 *   (déploiement existant, audit purgé) reçoit un premier dump quand même.
 *
 * Ceci est la couche « restauration fine » — la couche « disque mort » est le
 * snapshot de l'hébergeur (docs/ops/backup-restore.md). Hook off-site optionnel
 * via BACKUP_SYNC_COMMAND, jamais bloquant.
 */
#[AsCommand(
    name: 'app:db:backup',
    description: 'Activity-driven pg_dump backup (skips when nothing changed). Retention: 14 dumps. Runs nightly (cron-runner).',
)]
final class DatabaseBackupCommand extends Command
{
    private const RETENTION = 14;

    public function __construct(
        private readonly BackupCoverage $coverage,
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
        $dir = \is_string($dirOption) && '' !== $dirOption ? rtrim($dirOption, '/') : $this->defaultBackupDir;
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->error(\sprintf('Cannot create backup directory %s.', $dir));

            return Command::FAILURE;
        }

        // Purge des .part ORPHELINS (> 1 h) : un SIGKILL/OOM pendant pg_dump laisse un
        // .part que ni le finally (process mort) ni la rétention (glob *.dump) ne voient —
        // sans ce balayage ils s'accumulent jusqu'au disque plein (round 2, finding 3).
        // 1 h de grâce : ne jamais toucher le .part d'un dump concurrent encore en cours.
        foreach (glob($dir . '/clubscheduler-*.dump.part') ?: [] as $orphan) {
            $mtime = filemtime($orphan);
            if (false !== $mtime && $mtime < time() - 3600) {
                @unlink($orphan);
                $io->writeln(\sprintf('Removed orphaned partial %s.', basename($orphan)));
            }
        }

        $lastDumpAt = $this->coverage->latestDumpTime($dir);
        $lastActivityAt = $this->coverage->latestActivity();
        $io->writeln(\sprintf('activity=%s lastDump=%s', $lastActivityAt?->format('Y-m-d H:i:s') ?? 'none', $lastDumpAt?->format('Y-m-d H:i:s') ?? 'none'), OutputInterface::VERBOSITY_VERBOSE);

        if (!(bool) $input->getOption('force')) {
            if (null === $lastActivityAt) {
                // BOOTSTRAP (revue #258, finding 3) : aucun signal d'activité ne veut pas
                // dire base vide — un déploiement existant (colonne d'activité récente,
                // audit purgé) porte des données. S'il n'existe AUCUN dump et que la base
                // a des données : premier dump quand même. Sinon : vraiment rien à protéger.
                if (null === $lastDumpAt && $this->coverage->hasAnyData()) {
                    $io->writeln('No activity signal but the database holds data and no dump exists — bootstrap dump.');
                } else {
                    $io->writeln('No activity at all — nothing to protect, skipping.');

                    return Command::SUCCESS;
                }
            } elseif ($this->coverage->covers($lastDumpAt, $lastActivityAt)) {
                $io->writeln(\sprintf('No activity since last dump (%s) — skipping.', $lastDumpAt?->format('Y-m-d H:i') ?? '?'));

                return Command::SUCCESS;
            }
        }

        // T0 = DÉBUT du snapshot : pg_dump photographie la base au start. Le mtime final
        // est posé à T0 pour que toute écriture PENDANT le dump reste « non couverte ».
        $snapshotStart = new DateTimeImmutable;
        $final = \sprintf('%s/clubscheduler-%s.dump', $dir, $snapshotStart->format('Ymd-His-u'));
        // Écriture en .part puis rename : un dump interrompu (timeout/kill) ne matche
        // jamais le glob *.dump — il ne peut pas masquer les backups suivants.
        $partial = $final . '.part';

        try {
            $process = new Process(
                ['pg_dump', '--format=custom', '--file', $partial, '--host', $this->env('POSTGRES_HOST', 'postgres'), '--port', $this->env('POSTGRES_PORT', '5432'), '--username', $this->env('POSTGRES_USER', 'clubscheduler'), $this->env('POSTGRES_DB', 'clubscheduler')],
                env: ['PGPASSWORD' => $this->env('POSTGRES_PASSWORD', '')],
                timeout: 600,
            );
            $process->run();
            if (!$process->isSuccessful()) {
                // stderr de pg_dump : diagnostic sans secret (PGPASSWORD passe par l'env, pas argv).
                $io->error('pg_dump failed: ' . trim($process->getErrorOutput()));

                return Command::FAILURE;
            }
        } catch (Throwable $e) {
            // Timeout (ProcessTimedOutException) ou toute autre interruption : le .part
            // est purgé, jamais promu — le job est marqué failed, visible au panneau.
            $io->error('pg_dump interrupted: ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            if (is_file($partial) && !is_file($final)) {
                // Succès → promotion atomique + mtime = début du snapshot ; échec → purge.
                if (isset($process) && $process->isSuccessful()) {
                    if (!rename($partial, $final)) {
                        @unlink($partial);
                    } elseif (!touch($final, (int) $snapshotStart->format('U'))) {
                        // mtime reste « maintenant » (> T0) : direction SÛRE — au pire un
                        // re-dump de trop, jamais une activité crue couverte à tort.
                        $io->warning('Could not set the dump mtime to snapshot start — next run may re-dump once.');
                    }
                } else {
                    @unlink($partial);
                }
            }
        }

        // La promotion doit être VÉRIFIÉE : un rename en échec (permissions, disque) avec
        // un « Dump written » de succès serait un faux backup (round 2, finding 5).
        if (!is_file($final)) {
            $io->error('Dump promotion failed — no backup was produced.');

            return Command::FAILURE;
        }

        $size = filesize($final);
        $io->success(\sprintf('Dump written: %s (%s).', basename($final), false === $size ? '?' : $this->humanSize($size)));

        $this->applyRetention($dir, $io);
        $this->runSyncHook($io);

        return Command::SUCCESS;
    }

    private function applyRetention(string $dir, SymfonyStyle $io): void
    {
        $files = glob($dir . '/clubscheduler-*.dump') ?: [];
        // Le nom porte l'horodatage → l'ordre lexical EST l'ordre chronologique.
        sort($files);
        $excess = \count($files) - self::RETENTION;
        for ($i = 0; $i < $excess; ++$i) {
            @unlink($files[$i]);
            $io->writeln(\sprintf('Retention: removed %s.', basename($files[$i])));
        }
    }

    /** Off-site optionnel : commande shell posée par l'OP dans l'env (jamais une entrée requête). Échec = WARNING, jamais un échec du dump. */
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
}
