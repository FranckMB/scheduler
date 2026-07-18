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

    public function __construct(
        private ManagerRegistry $registry,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool}>
     */
    public function referentials(): array
    {
        return [
            $this->fromJobRun('school-holidays', 'Vacances scolaires', 'import-school-holidays'),
            $this->fromJobRun('public-holidays', 'Jours fériés', 'import-public-holidays'),
            $this->fromTables('ffbb-directory', 'Ligues & comités FFBB', ['ffbb_league', 'ffbb_committee']),
        ];
    }

    /** @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool} */
    private function fromJobRun(string $key, string $label, string $jobKey): array
    {
        $last = $this->connection()->fetchOne(
            'SELECT MAX(finished_at) FROM admin_job_run WHERE job_key = :job_key AND status = \'succeeded\'',
            ['job_key' => $jobKey],
        );

        return $this->row($key, $label, \is_string($last) ? $last : null, self::IMPORT_STALE_AFTER_DAYS);
    }

    /** @param list<string> $tables
     * @return array{key: string, label: string, lastUpdatedAt: ?string, staleAfterDays: int, stale: bool} */
    private function fromTables(string $key, string $label, array $tables): array
    {
        $latest = null;
        foreach ($tables as $table) {
            // Tables du catalogue fermé ci-dessus — jamais un nom venu d'une entrée.
            // `fetched_at` = l'instant du dernier fetch FFBB (FfbbClubPopulator).
            $value = $this->connection()->fetchOne(\sprintf('SELECT MAX(fetched_at) FROM %s', $table));
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
