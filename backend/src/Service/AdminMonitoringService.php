<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/** Read-only cross-tenant aggregates, deliberately executed through the admin connection. */
final readonly class AdminMonitoringService
{
    private const METRICS_WINDOW_DAYS = 30;

    public function __construct(private ManagerRegistry $registry) {}

    /**
     * @return array{
     *     clubs: array{total: int, active7d: int, active30d: int, new7d: int, unsubscribed: int},
     *     solver: array{windowDays: int, generations: int, completed: int, failed: int, infeasible: int, infeasibleRate: float, p50WallTimeMs: ?int, p95WallTimeMs: ?int, daily: list<array{date: string, generations: int, infeasible: int, p50WallTimeMs: ?int, p95WallTimeMs: ?int}>}
     * }
     */
    public function overview(): array
    {
        $clubs = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL) AS total,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL AND last_activity_at >= NOW() - INTERVAL '7 days') AS active_7d,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL AND last_activity_at >= NOW() - INTERVAL '30 days') AS active_30d,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL AND created_at >= NOW() - INTERVAL '7 days') AS new_7d,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NOT NULL) AS unsubscribed
            FROM club
            SQL);
        $solver = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) AS generations,
                COUNT(*) FILTER (WHERE status = 'COMPLETED') AS completed,
                COUNT(*) FILTER (WHERE status = 'FAILED') AS failed,
                COUNT(*) FILTER (WHERE status = 'INFEASIBLE') AS infeasible,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p95
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '30 days'
            SQL);
        $dailyRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT
                created_at::date AS day,
                COUNT(*) AS generations,
                COUNT(*) FILTER (WHERE status = 'INFEASIBLE') AS infeasible,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p95
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY created_at::date
            ORDER BY day
            SQL);

        $clubs = false === $clubs ? [] : $clubs;
        $solver = false === $solver ? [] : $solver;
        $generations = $this->integer($solver, 'generations');

        return [
            'clubs' => [
                'total' => $this->integer($clubs, 'total'),
                'active7d' => $this->integer($clubs, 'active_7d'),
                'active30d' => $this->integer($clubs, 'active_30d'),
                'new7d' => $this->integer($clubs, 'new_7d'),
                'unsubscribed' => $this->integer($clubs, 'unsubscribed'),
            ],
            'solver' => [
                'windowDays' => self::METRICS_WINDOW_DAYS,
                'generations' => $generations,
                'completed' => $this->integer($solver, 'completed'),
                'failed' => $this->integer($solver, 'failed'),
                'infeasible' => $this->integer($solver, 'infeasible'),
                'infeasibleRate' => $this->rate($this->integer($solver, 'infeasible'), $generations),
                'p50WallTimeMs' => $this->nullableInteger($solver, 'p50'),
                'p95WallTimeMs' => $this->nullableInteger($solver, 'p95'),
                'daily' => array_map(fn (array $row): array => [
                    'date' => (string) $row['day'],
                    'generations' => $this->integer($row, 'generations'),
                    'infeasible' => $this->integer($row, 'infeasible'),
                    'p50WallTimeMs' => $this->nullableInteger($row, 'p50'),
                    'p95WallTimeMs' => $this->nullableInteger($row, 'p95'),
                ], $dailyRows),
            ],
        ];
    }

    /**
     * @return array{items: list<array<string, bool|float|int|string|array<string, int|string|null>|null>>, pagination: array{page: int, limit: int, total: int, pages: int}, metricsWindowDays: int}
     */
    public function clubs(int $page, int $limit, ?string $query): array
    {
        $query = null !== $query && '' !== trim($query) ? trim($query) : null;
        $where = null === $query ? '' : 'WHERE c.name ILIKE :query OR c.slug ILIKE :query OR c.ffbb_club_code ILIKE :query';
        $parameters = null === $query ? [] : ['query' => '%' . $query . '%'];
        $total = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM club c ' . $where, $parameters);
        $parameters['limit'] = $limit;
        $parameters['offset'] = ($page - 1) * $limit;

        $rows = $this->connection()->fetchAllAssociative(<<<SQL
            WITH selected_clubs AS (
                SELECT c.*
                FROM club c
                $where
                ORDER BY COALESCE(c.last_activity_at, c.created_at) DESC, c.id
                LIMIT :limit OFFSET :offset
            )
            SELECT
                c.id,
                c.name,
                c.slug,
                c.ffbb_club_code,
                c.plan_id,
                c.billing_cycle,
                c.generation_count_season,
                c.created_at,
                c.last_activity_at,
                c.unsubscribed_at,
                current_season.id AS season_id,
                current_season.name AS season_name,
                current_season.status AS season_status,
                COALESCE(volumes.teams, 0) AS teams,
                COALESCE(volumes.venues, 0) AS venues,
                COALESCE(volumes.coaches, 0) AS coaches,
                COALESCE(volumes.constraints, 0) AS constraints,
                COALESCE(metric_agg.generations, 0) AS generations,
                COALESCE(metric_agg.infeasible, 0) AS infeasible,
                metric_agg.p50,
                metric_agg.p95,
                latest_metric.status AS latest_status,
                latest_metric.created_at AS latest_created_at
            FROM selected_clubs c
            LEFT JOIN LATERAL (
                SELECT s.id, s.name, s.status
                FROM season s
                WHERE s.club_id = c.id
                ORDER BY (CURRENT_DATE BETWEEN s.start_date AND s.end_date) DESC, s.start_date DESC
                LIMIT 1
            ) current_season ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    (SELECT COUNT(*) FROM team t WHERE t.season_id = current_season.id AND t.is_active = TRUE) AS teams,
                    (SELECT COUNT(*) FROM venue v WHERE v.season_id = current_season.id AND v.is_active = TRUE) AS venues,
                    (SELECT COUNT(*) FROM coach co WHERE co.season_id = current_season.id AND co.is_active = TRUE) AS coaches,
                    (SELECT COUNT(*) FROM "constraint" cn WHERE cn.season_id = current_season.id AND cn.is_active = TRUE) AS constraints
            ) volumes ON current_season.id IS NOT NULL
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*) AS generations,
                    COUNT(*) FILTER (WHERE sm.status = 'INFEASIBLE') AS infeasible,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY sm.wall_time_ms) FILTER (WHERE sm.wall_time_ms IS NOT NULL) AS p50,
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY sm.wall_time_ms) FILTER (WHERE sm.wall_time_ms IS NOT NULL) AS p95
                FROM solver_metrics sm
                WHERE sm.club_id = c.id AND sm.created_at >= NOW() - INTERVAL '30 days'
            ) metric_agg ON TRUE
            LEFT JOIN LATERAL (
                SELECT sm.status, sm.created_at
                FROM solver_metrics sm
                WHERE sm.club_id = c.id
                ORDER BY sm.created_at DESC
                LIMIT 1
            ) latest_metric ON TRUE
            ORDER BY COALESCE(c.last_activity_at, c.created_at) DESC, c.id
            SQL, $parameters);

        return [
            'items' => array_map(fn (array $row): array => $this->club($row), $rows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => 0 === $total ? 0 : (int) ceil($total / $limit),
            ],
            'metricsWindowDays' => self::METRICS_WINDOW_DAYS,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function club(array $row): array
    {
        $generations = $this->integer($row, 'generations');
        $infeasible = $this->integer($row, 'infeasible');

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'ffbbClubCode' => $this->nullableString($row, 'ffbb_club_code'),
            'planId' => $this->nullableInteger($row, 'plan_id'),
            'billingCycle' => $this->nullableString($row, 'billing_cycle'),
            'generationCountSeason' => $this->integer($row, 'generation_count_season'),
            'createdAt' => (string) $row['created_at'],
            'lastActivityAt' => $this->nullableString($row, 'last_activity_at'),
            'unsubscribed' => null !== $row['unsubscribed_at'],
            'currentSeason' => null === $row['season_id'] ? null : [
                'id' => (string) $row['season_id'],
                'name' => (string) $row['season_name'],
                'status' => (string) $row['season_status'],
            ],
            'volumes' => [
                'teams' => $this->integer($row, 'teams'),
                'venues' => $this->integer($row, 'venues'),
                'coaches' => $this->integer($row, 'coaches'),
                'constraints' => $this->integer($row, 'constraints'),
            ],
            'solver' => [
                'generations' => $generations,
                'infeasible' => $infeasible,
                'infeasibleRate' => $this->rate($infeasible, $generations),
                'p50WallTimeMs' => $this->nullableInteger($row, 'p50'),
                'p95WallTimeMs' => $this->nullableInteger($row, 'p95'),
                'latestStatus' => $this->nullableString($row, 'latest_status'),
                'latestAt' => $this->nullableString($row, 'latest_created_at'),
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

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (string) $value;
    }

    private function rate(int $count, int $total): float
    {
        return 0 === $total ? 0.0 : round($count / $total, 4);
    }
}
