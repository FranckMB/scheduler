<?php

declare(strict_types=1);

namespace App\AdminJob;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/** Admin-connection store. No command output or exception text is persisted. */
final readonly class AdminJobRunStore
{
    public function __construct(private ManagerRegistry $managerRegistry) {}

    public function tryAcquire(string $jobKey): bool
    {
        return (bool) $this->connection()->fetchOne(
            'SELECT pg_try_advisory_lock(hashtext(\'clubscheduler.admin_job\'), hashtext(:job_key))',
            ['job_key' => $jobKey],
        );
    }

    public function release(string $jobKey): void
    {
        $this->connection()->fetchOne(
            'SELECT pg_advisory_unlock(hashtext(\'clubscheduler.admin_job\'), hashtext(:job_key))',
            ['job_key' => $jobKey],
        );
    }

    public function start(AdminJobDefinition $definition, string $source, ?string $superAdminId): string
    {
        $connection = $this->connection();
        $connection->executeStatement(
            'UPDATE admin_job_run SET status = \'interrupted\', finished_at = NOW(), duration_ms = GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000))::INT WHERE job_key = :job_key AND status = \'running\'',
            ['job_key' => $definition->key],
        );

        $id = $this->newUuid();
        $connection->insert('admin_job_run', [
            'id' => $id,
            'job_key' => $definition->key,
            'command_name' => $definition->command,
            'source' => $source,
            'status' => 'running',
            'started_at' => (new DateTimeImmutable)->format('Y-m-d H:i:sP'),
            'super_admin_id' => $superAdminId,
        ]);

        return $id;
    }

    public function finish(string $runId, string $status, int $exitCode): void
    {
        $this->connection()->executeStatement(
            'UPDATE admin_job_run SET status = :status, finished_at = NOW(), duration_ms = GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000))::INT, exit_code = :exit_code WHERE id = :id',
            ['id' => $runId, 'status' => $status, 'exit_code' => $exitCode],
        );
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
