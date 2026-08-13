<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Read-only cross-tenant aggregates, deliberately executed through the admin connection.
 *
 * Capacité (P5-10) : ce que la télémétrie append-only `solver_metrics` dit de la
 * TENUE du système sous charge — volume, attente en file, durées par taille de
 * problème, mémoire de l'engine, issues. Fenêtre 90 jours : le pic de septembre
 * doit rester visible tout l'automne. Toutes les colonnes de capacité sont
 * nullable (ADD COLUMN additif du contrat 2.6) — l'historique d'avant la colonne
 * et les chemins terminaux (échec/timeout, engine muet) les laissent null, d'où
 * les FILTER partout et les helpers null-safe.
 */
final readonly class AdminCapacityService
{
    private const int WINDOW_DAYS = 90;

    public function __construct(
        private ManagerRegistry $registry,
    ) {}

    /**
     * @return array{
     *     windowDays: int,
     *     totalSolves: int,
     *     volume: array{perDay: array{p50: ?int, max: ?int, maxDate: ?string}, hourly: list<array{hour: int, solves: int}>},
     *     wait: array{queueP50Ms: ?int, queueP95Ms: ?int, queueMaxMs: ?int, engineWaitP95Ms: ?int},
     *     bySize: list<array{bucket: string, solves: int, p50WallTimeMs: ?int, p95WallTimeMs: ?int, p95BudgetRatio: ?float, unclosedProofRate: float}>,
     *     memory: array{peakP50Mb: ?float, peakP95Mb: ?float, peakMaxMb: ?float, lastBaselineMb: ?float},
     *     issues: array{byStatus: list<array{status: string, solves: int}>, payloadP95Bytes: ?int}
     * }
     */
    public function capacity(): array
    {
        // Volume : le débit quotidien (médiane et pic daté) sur la fenêtre. Le p50/max
        // portent sur les JOURS (combien de solves un jour typique / le jour le plus chargé).
        $perDay = $this->connection()->fetchAssociative(<<<'SQL'
            WITH daily AS (
                SELECT created_at::date AS day, COUNT(*) AS solves
                FROM solver_metrics
                WHERE created_at >= NOW() - INTERVAL '90 days'
                GROUP BY created_at::date
            )
            SELECT
                COALESCE(SUM(solves), 0) AS total,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY solves) AS p50_per_day,
                MAX(solves) AS max_per_day,
                (SELECT day FROM daily ORDER BY solves DESC, day DESC LIMIT 1) AS max_day
            FROM daily
            SQL);

        // Profil horaire : heure LOCALE (Europe/Paris) du passage en file — sinon le pic
        // du soir se lirait décalé de l'UTC. Seules les heures peuplées remontent (queued_at
        // null = historique pré-2.6, ignoré). Le front n'affiche que les heures non vides.
        $hourlyRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT
                EXTRACT(HOUR FROM (queued_at AT TIME ZONE 'Europe/Paris'))::int AS hour,
                COUNT(*) AS solves
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '90 days' AND queued_at IS NOT NULL
            GROUP BY 1
            ORDER BY 1
            SQL);

        // Attente : file applicative (solve_started_at − queued_at) en secondes → ms, plus
        // l'attente côté engine (sémaphore par club). Chaque agrégat filtre ses propres nulls.
        // La mémoire (peak/baseline RSS) et le payload p95 partagent la même fenêtre : un seul balayage.
        $scalars = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (solve_started_at - queued_at)))
                    FILTER (WHERE solve_started_at IS NOT NULL AND queued_at IS NOT NULL) AS queue_p50_s,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (solve_started_at - queued_at)))
                    FILTER (WHERE solve_started_at IS NOT NULL AND queued_at IS NOT NULL) AS queue_p95_s,
                MAX(EXTRACT(EPOCH FROM (solve_started_at - queued_at)))
                    FILTER (WHERE solve_started_at IS NOT NULL AND queued_at IS NOT NULL) AS queue_max_s,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY engine_wait_ms)
                    FILTER (WHERE engine_wait_ms IS NOT NULL) AS engine_wait_p95,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY peak_rss_mb) FILTER (WHERE peak_rss_mb IS NOT NULL) AS peak_p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY peak_rss_mb) FILTER (WHERE peak_rss_mb IS NOT NULL) AS peak_p95,
                MAX(peak_rss_mb) AS peak_max,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY payload_bytes) FILTER (WHERE payload_bytes IS NOT NULL) AS payload_p95
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '90 days'
            SQL);

        // Baseline mémoire = le RSS du process AVANT solve le plus récent : « à vide, le
        // worker engine pèse déjà tant ». Dernière valeur non nulle de la fenêtre.
        $lastBaseline = $this->connection()->fetchOne(<<<'SQL'
            SELECT rss_before_mb
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '90 days' AND rss_before_mb IS NOT NULL
            ORDER BY created_at DESC
            LIMIT 1
            SQL);

        // Durées par TAILLE du problème (nb_teams × nb_venues) alignées aux tiers moteur
        // (≤50 / 51-200 / >200). Caveat assumé : nb_teams/nb_venues sont la taille du CLUB
        // (saison entière) — un overlay ne solve qu'un sous-ensemble, mais porte le compte
        // saison. `wall` = solve entier (total_wall_time_ms) avec repli sur wall_time_ms
        // (historique pré-2.6). % budget = wall / budget_seconds. « preuve non fermée » =
        // solver_status_detail FEASIBLE (trouvé, pas prouvé optimal) sur les solves qui ont
        // rapporté un détail (les autres — échec, pré-2.6 — hors dénominateur).
        $sizeRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            WITH sized AS (
                SELECT
                    nb_teams * nb_venues AS problem_size,
                    COALESCE(total_wall_time_ms, wall_time_ms) AS wall_ms,
                    budget_seconds,
                    solver_status_detail
                FROM solver_metrics
                WHERE created_at >= NOW() - INTERVAL '90 days'
                    AND nb_teams IS NOT NULL AND nb_venues IS NOT NULL
            )
            SELECT
                CASE WHEN problem_size <= 50 THEN 'small' WHEN problem_size <= 200 THEN 'medium' ELSE 'large' END AS bucket,
                MIN(CASE WHEN problem_size <= 50 THEN 1 WHEN problem_size <= 200 THEN 2 ELSE 3 END) AS bucket_order,
                COUNT(*) AS solves,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_ms) FILTER (WHERE wall_ms IS NOT NULL) AS p50_wall,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY wall_ms) FILTER (WHERE wall_ms IS NOT NULL) AS p95_wall,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY (wall_ms / 1000.0 / budget_seconds))
                    FILTER (WHERE wall_ms IS NOT NULL AND budget_seconds IS NOT NULL AND budget_seconds > 0) AS p95_budget_ratio,
                COUNT(*) FILTER (WHERE solver_status_detail = 'FEASIBLE') AS feasible,
                COUNT(*) FILTER (WHERE solver_status_detail IS NOT NULL) AS with_detail
            FROM sized
            GROUP BY bucket
            ORDER BY bucket_order
            SQL);

        // Issues : répartition des statuts terminaux (le front en fait des %).
        $statusRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT status, COUNT(*) AS solves
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '90 days'
            GROUP BY status
            ORDER BY COUNT(*) DESC, status
            SQL);

        $perDay = false === $perDay ? [] : $perDay;
        $scalars = false === $scalars ? [] : $scalars;

        return [
            'windowDays' => self::WINDOW_DAYS,
            'totalSolves' => $this->integer($perDay, 'total'),
            'volume' => [
                'perDay' => [
                    'p50' => $this->nullableInteger($perDay, 'p50_per_day'),
                    'max' => $this->nullableInteger($perDay, 'max_per_day'),
                    'maxDate' => $this->nullableString($perDay, 'max_day'),
                ],
                'hourly' => array_map(fn (array $row): array => [
                    'hour' => (int) $row['hour'],
                    'solves' => $this->integer($row, 'solves'),
                ], $hourlyRows),
            ],
            'wait' => [
                'queueP50Ms' => $this->secondsToMs($scalars, 'queue_p50_s'),
                'queueP95Ms' => $this->secondsToMs($scalars, 'queue_p95_s'),
                'queueMaxMs' => $this->secondsToMs($scalars, 'queue_max_s'),
                'engineWaitP95Ms' => $this->nullableInteger($scalars, 'engine_wait_p95'),
            ],
            'bySize' => array_map(fn (array $row): array => [
                'bucket' => (string) $row['bucket'],
                'solves' => $this->integer($row, 'solves'),
                'p50WallTimeMs' => $this->nullableInteger($row, 'p50_wall'),
                'p95WallTimeMs' => $this->nullableInteger($row, 'p95_wall'),
                'p95BudgetRatio' => $this->nullableRatio($row, 'p95_budget_ratio'),
                'unclosedProofRate' => $this->rate($this->integer($row, 'feasible'), $this->integer($row, 'with_detail')),
            ], $sizeRows),
            'memory' => [
                'peakP50Mb' => $this->nullableFloat($scalars, 'peak_p50'),
                'peakP95Mb' => $this->nullableFloat($scalars, 'peak_p95'),
                'peakMaxMb' => $this->nullableFloat($scalars, 'peak_max'),
                'lastBaselineMb' => false === $lastBaseline ? null : round((float) $lastBaseline, 1),
            ],
            'issues' => [
                'byStatus' => array_map(fn (array $row): array => [
                    'status' => (string) $row['status'],
                    'solves' => $this->integer($row, 'solves'),
                ], $statusRows),
                'payloadP95Bytes' => $this->nullableInteger($scalars, 'payload_p95'),
            ],
        ];
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        return (int) ($row[$key] ?? 0);
    }

    /** @param array<string, mixed> $row */
    private function nullableInteger(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (int) round((float) $value);
    }

    /** @param array<string, mixed> $row Percentiles en secondes → ms (l'unité du front, cf. engineWaitMs). */
    private function secondsToMs(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (int) round(((float) $value) * 1000);
    }

    /** @param array<string, mixed> $row Mémoire (MiB) et ratios budget : 1 décimale suffit, la précision brute est du bruit. */
    private function nullableFloat(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : round((float) $value, 1);
    }

    /** @param array<string, mixed> $row Ratio (fraction du budget) : 4 décimales comme un taux, le front l'affiche en %. */
    private function nullableRatio(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : round((float) $value, 4);
    }

    private function rate(int $count, int $total): float
    {
        return 0 === $total ? 0.0 : round($count / $total, 4);
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (string) $value;
    }
}
