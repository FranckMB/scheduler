<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;

/**
 * Data-freshness board (console superadmin) : « mes données de référence
 * sont-elles à jour ? ». Lecture seule sur la connexion admin — rend VISIBLE
 * l'import automatique qui meurt en silence (le besoin fondateur « mise à jour
 * auto » devient redevable). Le job d'alerte (app:health:alert) consomme la même
 * liste : un référentiel périmé déclenche un email.
 */
final readonly class AdminDataFreshnessService
{
    /** Imports trimestriels (SA3) : périmé au-delà d'un trimestre + marge. */
    private const IMPORT_STALE_AFTER_DAYS = 100;

    /** Référentiel FFBB : rafraîchi au fil des créations de clubs — une saison + marge. */
    private const FFBB_STALE_AFTER_DAYS = 400;

    /** Backup piloté par l'activité : périmé si de l'activité reste non couverte > 26 h. */
    private const BACKUP_STALE_AFTER_HOURS = 26;

    public function __construct(
        private ManagerRegistry $registry,
        private ClockInterface $clock,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%/var/backups')]
        private string $backupDir,
    ) {}

    /**
     * @return list<array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}>
     */
    public function referentials(): array
    {
        return [
            $this->fromImport('school-holidays', 'Vacances scolaires', 'import-school-holidays', 'school_holiday_period'),
            $this->fromImport('public-holidays', 'Jours fériés', 'import-public-holidays', 'public_holiday'),
            $this->fromTables('ffbb-directory', 'Ligues & comités FFBB', 'fetched_at', ['ffbb_league', 'ffbb_committee']),
            $this->backupRow(),
        ];
    }

    /**
     * Backup PILOTÉ PAR L'ACTIVITÉ : périmé s'il existe de l'activité PLUS RÉCENTE que le
     * dernier dump ET que ce dump a plus de 26 h (une base dormante n'alerte jamais —
     * décision fondateur 2026-07-18). Jamais de dump MAIS de l'activité → périmé.
     * L'évaluateur d'alertes ramasse la ligne tel quel (freshness:db-backup → email).
     *
     * @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}
     */
    private function backupRow(): array
    {
        $lastDump = null;
        foreach (glob(rtrim($this->backupDir, '/') . '/clubscheduler-*.dump') ?: [] as $file) {
            $mtime = filemtime($file);
            if (false !== $mtime && (null === $lastDump || $mtime > $lastDump)) {
                $lastDump = $mtime;
            }
        }
        $lastDumpAt = null === $lastDump ? null : new DateTimeImmutable('@' . $lastDump);

        $activity = $this->connection()->fetchOne(
            'SELECT GREATEST(
                (SELECT MAX(last_activity_at) FROM club),
                (SELECT MAX(created_at) FROM solver_metrics),
                (SELECT MAX(occurred_at) FROM audit_log)
            )',
        );
        $lastActivityAt = \is_string($activity) ? new DateTimeImmutable($activity) : null;

        $uncovered = null !== $lastActivityAt && (null === $lastDumpAt || $lastActivityAt > $lastDumpAt);
        $stale = $uncovered && (null === $lastDumpAt || $lastDumpAt < $this->clock->now()->modify(\sprintf('-%d hours', self::BACKUP_STALE_AFTER_HOURS)));

        return [
            'key' => 'db-backup',
            'label' => 'Sauvegarde base de données',
            'lastUpdatedAt' => $lastDumpAt?->format(\DATE_ATOM),
            'staleAfterDays' => 1,
            'stale' => $stale,
        ];
    }

    /**
     * DOUBLE signal (revue #257, finding 3) : le dernier run RÉUSSI du job planifié
     * (admin_job_run) OU la dernière ÉCRITURE de données (created_at de la table) —
     * le plus récent des deux. Un import lancé en CLI direct (documenté dans
     * commands.md) n'écrit pas de ligne admin_job_run mais crée des lignes de
     * données : sans ce second signal, le board resterait « Périmé » après un
     * import parfaitement réussi. (Limite assumée : un import direct qui ne crée
     * AUCUNE ligne nouvelle ne rafraîchit pas le signal.).
     *
     * @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}
     */
    private function fromImport(string $key, string $label, string $jobKey, string $dataTable): array
    {
        $lastRun = $this->connection()->fetchOne(
            'SELECT MAX(finished_at) FROM admin_job_run WHERE job_key = :job_key AND status = \'succeeded\'',
            ['job_key' => $jobKey],
        );
        // Table du catalogue fermé ci-dessus — jamais un nom venu d'une entrée.
        $lastData = $this->connection()->fetchOne(\sprintf('SELECT MAX(created_at) FROM %s', $dataTable));

        $candidates = array_filter([$lastRun, $lastData], static fn (mixed $value): bool => \is_string($value));

        return $this->row($key, $label, [] === $candidates ? null : max($candidates), self::IMPORT_STALE_AFTER_DAYS);
    }

    /** @param list<string> $tables
     * @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool} */
    private function fromTables(string $key, string $label, string $timestampColumn, array $tables): array
    {
        $latest = null;
        foreach ($tables as $table) {
            // Tables/colonne du catalogue fermé ci-dessus — jamais un nom venu d'une entrée.
            // `fetched_at` = l'instant du dernier fetch FFBB (FfbbClubPopulator).
            $value = $this->connection()->fetchOne(\sprintf('SELECT MAX(%s) FROM %s', $timestampColumn, $table));
            if (\is_string($value) && (null === $latest || $value > $latest)) {
                $latest = $value;
            }
        }

        return $this->row($key, $label, $latest, self::FFBB_STALE_AFTER_DAYS);
    }

    /** @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool} */
    private function row(string $key, string $label, ?string $lastUpdatedAt, int $staleAfterDays): array
    {
        // Aucune donnée du tout = périmé (fail-visible) : un référentiel jamais
        // importé doit s'afficher rouge, pas « pas d'info ».
        $stale = null === $lastUpdatedAt
            || new DateTimeImmutable($lastUpdatedAt) < $this->clock->now()->modify(\sprintf('-%d days', $staleAfterDays));

        return [
            'key' => $key,
            'label' => $label,
            'lastUpdatedAt' => $lastUpdatedAt,
            'staleAfterDays' => $staleAfterDays,
            'stale' => $stale,
        ];
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
