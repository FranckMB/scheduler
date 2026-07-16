<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\SolverMetric;
use Doctrine\ORM\EntityManagerInterface;

/** Stores one immutable history row for every generation attempt. */
final class SolverMetricsRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @param array<string, mixed>|null $result */
    public function record(Schedule $schedule, ?array $result = null): void
    {
        $metric = $result['metrics'] ?? $result['solver_metrics'] ?? [];
        $metric = \is_array($metric) ? $metric : [];
        $engineStatus = strtolower((string) ($result['status'] ?? ''));
        $status = 'infeasible' === $engineStatus ? 'INFEASIBLE' : $schedule->getStatus()->value;

        $this->entityManager->persist(new SolverMetric(
            scheduleId: $schedule->getId(),
            clubId: $schedule->getClubId(),
            status: $status,
            wallTimeMs: $this->intMetric($metric, 'wall_time_ms', 'wallTimeMs') ?? $schedule->getSolverWallTimeMs(),
            nbVariables: $this->intMetric($metric, 'nb_variables', 'nbVariables') ?? $schedule->getSolverNbVariables(),
            nbConstraints: $this->intMetric($metric, 'nb_constraints', 'nbConstraints') ?? $schedule->getSolverNbConstraints(),
            nbConflicts: $this->intMetric($metric, 'nb_conflicts', 'nbConflicts') ?? $schedule->getSolverNbConflicts(),
            score: $schedule->getScore(),
            solverVersion: $this->stringMetric($metric, 'solver_version', 'solverVersion') ?? $schedule->getSolverVersion(),
        ));
    }

    /** @param array<string, mixed> $metrics */
    private function intMetric(array $metrics, string $snake, string $camel): ?int
    {
        $value = $metrics[$snake] ?? $metrics[$camel] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $metrics */
    private function stringMetric(array $metrics, string $snake, string $camel): ?string
    {
        $value = $metrics[$snake] ?? $metrics[$camel] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
