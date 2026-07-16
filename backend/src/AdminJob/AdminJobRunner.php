<?php

declare(strict_types=1);

namespace App\AdminJob;

use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Throwable;

/** Runs one allowlisted job while guaranteeing history and non-overlap. */
final readonly class AdminJobRunner
{
    public function __construct(private AdminJobRunStore $store) {}

    /** @param callable(): int $execute */
    public function run(AdminJobDefinition $definition, string $source, ?string $superAdminId, callable $execute, ?DateTimeImmutable $scheduledFor = null): int
    {
        if (!$this->store->tryAcquire($definition->key)) {
            throw new AdminJobAlreadyRunning(\sprintf('Job "%s" is already running.', $definition->key));
        }

        try {
            $runId = $this->store->start($definition, $source, $superAdminId, $scheduledFor);
            try {
                $exitCode = $execute();
            } catch (Throwable $error) {
                $this->store->finish($runId, 'failed', Command::FAILURE);

                throw $error;
            }

            $this->store->finish($runId, Command::SUCCESS === $exitCode ? 'succeeded' : 'failed', $exitCode);

            return $exitCode;
        } finally {
            $this->store->release($definition->key);
        }
    }
}
