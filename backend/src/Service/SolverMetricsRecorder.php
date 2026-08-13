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

    /**
     * @param array<string, mixed>|null $result
     * @param ?int                      $payloadBytes taille du JSON envoyé à l'engine (déjà sérialisé
     *                                                pour le hash côté handler — coût nul). Null sur les
     *                                                chemins sans payload (échec précoce, commande de
     *                                                réconciliation).
     */
    public function record(Schedule $schedule, ?array $result = null, ?int $payloadBytes = null): void
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
            queuedAt: $schedule->getQueuedAt(),
            solveStartedAt: $schedule->getSolveStartedAt(),
            payloadBytes: $payloadBytes,
            totalWallTimeMs: $this->intMetric($metric, 'total_wall_time_ms', 'totalWallTimeMs'),
            cpuTimeMs: $this->intMetric($metric, 'cpu_time_ms', 'cpuTimeMs'),
            workers: $this->intMetric($metric, 'workers', 'workers'),
            budgetSeconds: $this->intMetric($metric, 'budget_seconds', 'budgetSeconds'),
            solverStatusDetail: $this->stringMetric($metric, 'solver_status_detail', 'solverStatusDetail'),
            peakRssMb: $this->floatMetric($metric, 'peak_rss_mb', 'peakRssMb'),
            rssBeforeMb: $this->floatMetric($metric, 'rss_before_mb', 'rssBeforeMb'),
            engineWaitMs: $this->intMetric($metric, 'engine_wait_ms', 'engineWaitMs'),
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

    /** @param array<string, mixed> $metrics */
    private function floatMetric(array $metrics, string $snake, string $camel): ?float
    {
        $value = $metrics[$snake] ?? $metrics[$camel] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
