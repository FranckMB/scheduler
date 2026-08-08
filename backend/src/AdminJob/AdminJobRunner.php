<?php

declare(strict_types=1);

namespace App\AdminJob;

use App\Enum\AdminJobSource;
use App\Enum\AdminJobStatus;
use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Throwable;

/** Runs one allowlisted job while guaranteeing history and non-overlap. */
final readonly class AdminJobRunner
{
    public function __construct(private AdminJobRunStore $store) {}

    /** @param callable(): int $execute */
    public function run(AdminJobDefinition $definition, AdminJobSource $source, ?string $superAdminId, callable $execute, ?DateTimeImmutable $scheduledFor = null): int
    {
        // Verrou sur la clé EFFECTIVE (peut être partagée avec un job planifié — SA4),
        // historique sous `key` (toujours propre à la définition, jamais mélangé).
        if (!$this->store->tryAcquire($definition->effectiveLockKey())) {
            throw new AdminJobAlreadyRunning(\sprintf('Job "%s" is already running.', $definition->key));
        }

        try {
            $runId = $this->store->start($definition, $source, $superAdminId, $scheduledFor);
            try {
                $exitCode = $execute();
            } catch (Throwable $error) {
                $this->store->finish($runId, AdminJobStatus::FAILED, Command::FAILURE);

                throw $error;
            }

            $this->store->finish($runId, Command::SUCCESS === $exitCode ? AdminJobStatus::SUCCEEDED : AdminJobStatus::FAILED, $exitCode);

            return $exitCode;
        } finally {
            $this->store->release($definition->effectiveLockKey());
        }
    }
}
