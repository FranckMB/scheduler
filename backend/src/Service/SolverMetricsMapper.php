<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;

/**
 * Maps the engine result's score + solver metrics onto a Schedule (BCK-04:
 * extracted from GenerateScheduleHandler). Accepts both snake_case and camelCase
 * metric keys — the backend↔engine contract has drifted between the two.
 */
final class SolverMetricsMapper
{
    /** @param array<string, mixed> $result */
    public function apply(Schedule $schedule, array $result): void
    {
        $metrics = $result['metrics'] ?? $result['solver_metrics'] ?? null;
        if (\is_array($metrics)) {
            $this->applyScoreAndMetrics($schedule, $result);
        } elseif (isset($result['score']) && is_numeric($result['score'])) {
            $schedule->setScore((int) $result['score']);
        }
    }

    /** @param array<string, mixed> $result */
    private function applyScoreAndMetrics(Schedule $schedule, array $result): void
    {
        if (isset($result['score']) && is_numeric($result['score'])) {
            $schedule->setScore((int) $result['score']);
        }

        $metrics = $result['metrics'] ?? $result['solver_metrics'] ?? [];
        if (!\is_array($metrics)) {
            return;
        }

        if (isset($metrics['solver_version']) || isset($metrics['solverVersion'])) {
            $schedule->setSolverVersion((string) ($metrics['solver_version'] ?? $metrics['solverVersion']));
        }
        if (isset($metrics['constraint_version']) || isset($metrics['constraintVersion'])) {
            $schedule->setConstraintVersion((string) ($metrics['constraint_version'] ?? $metrics['constraintVersion']));
        }
        if (isset($metrics['score_formula_version']) || isset($metrics['scoreFormulaVersion'])) {
            $schedule->setScoreFormulaVersion((string) ($metrics['score_formula_version'] ?? $metrics['scoreFormulaVersion']));
        }
        if ((isset($metrics['nb_variables']) && is_numeric($metrics['nb_variables'])) || (isset($metrics['nbVariables']) && is_numeric($metrics['nbVariables']))) {
            $schedule->setSolverNbVariables((int) ($metrics['nb_variables'] ?? $metrics['nbVariables']));
        }
        if ((isset($metrics['nb_constraints']) && is_numeric($metrics['nb_constraints'])) || (isset($metrics['nbConstraints']) && is_numeric($metrics['nbConstraints']))) {
            $schedule->setSolverNbConstraints((int) ($metrics['nb_constraints'] ?? $metrics['nbConstraints']));
        }
        if ((isset($metrics['nb_conflicts']) && is_numeric($metrics['nb_conflicts'])) || (isset($metrics['nbConflicts']) && is_numeric($metrics['nbConflicts']))) {
            $schedule->setSolverNbConflicts((int) ($metrics['nb_conflicts'] ?? $metrics['nbConflicts']));
        }
        if ((isset($metrics['wall_time_ms']) && is_numeric($metrics['wall_time_ms'])) || (isset($metrics['wallTimeMs']) && is_numeric($metrics['wallTimeMs']))) {
            $schedule->setSolverWallTimeMs((int) ($metrics['wall_time_ms'] ?? $metrics['wallTimeMs']));
        }
    }
}
