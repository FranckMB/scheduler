<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;
use App\Entity\SolverMetric;
use App\Entity\Team;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Stores one immutable history row for every generation attempt (append-only —
 * see SolverMetric). Les dimensions d'analyse (type du plan, tailles) sont
 * dénormalisées ICI, à la capture : la métrique doit rester lisible après la
 * mort de la version ou du plan qu'elle nomme.
 */
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
            createdAt: null,
            planType: $this->planType($schedule),
            nbTeams: $this->activeCount(Team::class, $schedule),
            nbVenues: $this->activeCount(Venue::class, $schedule),
        ));
    }

    /**
     * Best-effort : la capture ne doit JAMAIS faire échouer la génération. Un plan
     * disparu sous les pieds (reset concurrent) → null, pas d'exception. SQL brut
     * (comme les lectures de plan du provisioner) — le type est copié, pas joint.
     */
    private function planType(Schedule $schedule): ?string
    {
        try {
            $type = $this->entityManager->getConnection()->fetchOne(
                'SELECT type FROM schedule_plan WHERE id = :pid',
                ['pid' => $schedule->getSchedulePlanId()],
            );

            return \is_string($type) && '' !== $type ? $type : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param class-string $entityClass */
    private function activeCount(string $entityClass, Schedule $schedule): ?int
    {
        try {
            return $this->entityManager->getRepository($entityClass)->count([
                'seasonId' => $schedule->getSeasonId(),
                'isActive' => true,
            ]);
        } catch (Throwable) {
            return null;
        }
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
