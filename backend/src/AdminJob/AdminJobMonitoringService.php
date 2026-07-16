<?php

declare(strict_types=1);

namespace App\AdminJob;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/** Read-only operational job state for the super-admin console. */
final readonly class AdminJobMonitoringService
{
    public function __construct(
        private AdminJobCatalog $catalog,
        private ManagerRegistry $managerRegistry,
    ) {}

    /**
     * @return array{items: list<array{
     *     key: string,
     *     label: string,
     *     command: string,
     *     cadence: string,
     *     latestRun: array{id: string, status: string, source: string, startedAt: string, finishedAt: ?string, durationMs: ?int, exitCode: ?int}|null
     * }>}
     */
    public function jobs(): array
    {
        $latestByKey = [];
        foreach ($this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT ON (job_key)
                id, job_key, status, source, started_at, finished_at, duration_ms, exit_code
            FROM admin_job_run
            ORDER BY job_key, started_at DESC, id DESC
            SQL) as $row) {
            $latestByKey[(string) $row['job_key']] = $row;
        }

        return [
            'items' => array_map(
                fn (AdminJobDefinition $definition): array => $this->job($definition, $latestByKey[$definition->key] ?? null),
                $this->catalog->all(),
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $latest
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     command: string,
     *     cadence: string,
     *     latestRun: array{id: string, status: string, source: string, startedAt: string, finishedAt: ?string, durationMs: ?int, exitCode: ?int}|null
     * }
     */
    private function job(AdminJobDefinition $definition, ?array $latest): array
    {
        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'command' => $definition->command,
            'cadence' => $definition->cadence,
            'latestRun' => null === $latest ? null : [
                'id' => (string) $latest['id'],
                'status' => (string) $latest['status'],
                'source' => (string) $latest['source'],
                'startedAt' => (string) $latest['started_at'],
                'finishedAt' => null === $latest['finished_at'] ? null : (string) $latest['finished_at'],
                'durationMs' => null === $latest['duration_ms'] ? null : (int) $latest['duration_ms'],
                'exitCode' => null === $latest['exit_code'] ? null : (int) $latest['exit_code'],
            ],
        ];
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
