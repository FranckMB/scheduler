<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\AdminCapacityService;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * P5-10 — les agrégats de capacité lus de `solver_metrics` par la connexion `admin`.
 *
 * Même patron d'isolation qu'{@see AdminMonitoringClubsPlanTest} : on ouvre une
 * transaction SUR la connexion `admin` (superuser, hors dama), on VIDE la table
 * dans cette transaction pour un décor déterministe (capacity() est GLOBAL — aucun
 * filtre par club — donc des lignes de fixtures voisines fausseraient les
 * percentiles), on sème un jeu connu, on interroge le service (qui voit nos lignes
 * non committées, même session), et on `rollBack` au teardown : le DELETE et les
 * semis disparaissent, rien ne fuit.
 *
 * Ce qui est gardé : les tranches de taille, des percentiles simples, le null-safe
 * des lignes pré-2.6 (tout à null sauf le statut), le repli wall_time_ms de
 * l'historique, et la fenêtre 90 jours qui exclut le vieux.
 */
#[Group('phase1')]
#[Group('integration')]
final class AdminCapacityServiceTest extends KernelTestCase
{
    private Connection $admin;

    private bool $inTransaction = false;

    public function testCapacityAggregatesSizeBucketsPercentilesAndIsNullSafe(): void
    {
        // Petite tranche (5 × 2 = 10 ≤ 50). Deux solves : wall 2000 / 4000.
        $this->seedMetric([
            'status' => 'COMPLETED', 'nb_teams' => 5, 'nb_venues' => 2,
            'total_wall_time_ms' => 2000, 'budget_seconds' => 60, 'solver_status_detail' => 'OPTIMAL',
            'peak_rss_mb' => 100.0, 'payload_bytes' => 1000, 'engine_wait_ms' => 200,
            'queued_at' => '2026-08-13 20:30:00+02', 'solve_started_at' => '2026-08-13 20:30:10+02',
        ]);
        $this->seedMetric([
            'status' => 'COMPLETED', 'nb_teams' => 5, 'nb_venues' => 2,
            'total_wall_time_ms' => 4000, 'budget_seconds' => 60, 'solver_status_detail' => 'FEASIBLE',
            'peak_rss_mb' => 200.0, 'rss_before_mb' => 60.0, 'payload_bytes' => 3000, 'engine_wait_ms' => 600,
            'queued_at' => '2026-08-13 20:30:00+02', 'solve_started_at' => '2026-08-13 20:30:30+02',
        ]);
        // Grande tranche (30 × 10 = 300 > 200). total_wall_time_ms null → repli sur wall_time_ms.
        $this->seedMetric([
            'status' => 'INFEASIBLE', 'nb_teams' => 30, 'nb_venues' => 10,
            'wall_time_ms' => 180000, 'budget_seconds' => 180, 'peak_rss_mb' => 400.0,
        ]);
        // Ligne pré-2.6 : tout à null sauf le statut. Comptée, mais hors tranche/percentiles.
        $this->seedMetric(['status' => 'FAILED']);
        // Vieille ligne (120 j) : HORS fenêtre — ne doit apparaître nulle part.
        $this->seedMetric([
            'status' => 'COMPLETED', 'nb_teams' => 5, 'nb_venues' => 2,
            'total_wall_time_ms' => 999999, 'age_days' => 120,
        ]);

        $capacity = self::getContainer()->get(AdminCapacityService::class)->capacity();

        self::assertSame(90, $capacity['windowDays']);
        self::assertSame(4, $capacity['totalSolves'], 'la vieille ligne est exclue');

        // Volume : un seul jour peuplé (les 4 lignes NOW()), 4 solves.
        self::assertSame(4, $capacity['volume']['perDay']['p50']);
        self::assertSame(4, $capacity['volume']['perDay']['max']);
        self::assertSame([['hour' => 20, 'solves' => 2]], $capacity['volume']['hourly']);

        // Attente en file : [10 s, 30 s] → p50 20 s, p95 29 s, max 30 s (en ms). Engine p95 sur [200, 600].
        self::assertSame(20000, $capacity['wait']['queueP50Ms']);
        self::assertSame(29000, $capacity['wait']['queueP95Ms']);
        self::assertSame(30000, $capacity['wait']['queueMaxMs']);
        self::assertSame(580, $capacity['wait']['engineWaitP95Ms']);

        // Tranches : petite (2 solves) puis grande (1). Aucune tranche moyenne (aucune donnée).
        self::assertCount(2, $capacity['bySize']);
        $small = $capacity['bySize'][0];
        self::assertSame('small', $small['bucket']);
        self::assertSame(2, $small['solves']);
        self::assertSame(3000, $small['p50WallTimeMs']);
        self::assertSame(3900, $small['p95WallTimeMs']);
        self::assertEqualsWithDelta(0.065, $small['p95BudgetRatio'], 0.0001);
        self::assertSame(0.5, $small['unclosedProofRate'], '1 FEASIBLE sur 2 détails rapportés');

        $large = $capacity['bySize'][1];
        self::assertSame('large', $large['bucket']);
        self::assertSame(1, $large['solves']);
        self::assertSame(180000, $large['p50WallTimeMs'], 'repli sur wall_time_ms quand total_wall_time_ms est null');
        self::assertEqualsWithDelta(1.0, $large['p95BudgetRatio'], 0.0001);
        self::assertSame(0.0, $large['unclosedProofRate'], 'aucun détail rapporté → dénominateur nul → 0');

        // Mémoire : peaks [100, 200, 400]. Baseline = la seule ligne portant rss_before_mb.
        self::assertSame(200.0, $capacity['memory']['peakP50Mb']);
        self::assertEqualsWithDelta(380.0, $capacity['memory']['peakP95Mb'], 0.0001);
        self::assertSame(400.0, $capacity['memory']['peakMaxMb']);
        self::assertSame(60.0, $capacity['memory']['lastBaselineMb']);

        // Issues : 2 COMPLETED, 1 INFEASIBLE, 1 FAILED. Payload p95 sur [1000, 3000].
        $byStatus = [];
        foreach ($capacity['issues']['byStatus'] as $row) {
            $byStatus[$row['status']] = $row['solves'];
        }
        ksort($byStatus);
        self::assertSame(['COMPLETED' => 2, 'FAILED' => 1, 'INFEASIBLE' => 1], $byStatus);
        self::assertSame(2900, $capacity['issues']['payloadP95Bytes']);
    }

    public function testEmptyWindowIsNullSafeEverywhere(): void
    {
        // Table vidée dans la transaction, rien de semé : aucun crash, tout null/0/vide.
        $capacity = self::getContainer()->get(AdminCapacityService::class)->capacity();

        self::assertSame(0, $capacity['totalSolves']);
        self::assertNull($capacity['volume']['perDay']['p50']);
        self::assertNull($capacity['volume']['perDay']['max']);
        self::assertNull($capacity['volume']['perDay']['maxDate']);
        self::assertSame([], $capacity['volume']['hourly']);
        self::assertNull($capacity['wait']['queueP95Ms']);
        self::assertNull($capacity['wait']['engineWaitP95Ms']);
        self::assertSame([], $capacity['bySize']);
        self::assertNull($capacity['memory']['peakP50Mb']);
        self::assertNull($capacity['memory']['lastBaselineMb']);
        self::assertSame([], $capacity['issues']['byStatus']);
        self::assertNull($capacity['issues']['payloadP95Bytes']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $connection = $registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $this->admin = $connection;
        $this->admin->beginTransaction();
        $this->inTransaction = true;
        // Décor déterministe : capacity() n'a aucun filtre par club, donc on part d'une
        // table vide (rétabli par le rollBack du teardown — aucune ligne committée effacée).
        $this->admin->executeStatement('DELETE FROM solver_metrics');
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    /** @param array<string, scalar|null> $fields */
    private function seedMetric(array $fields): void
    {
        $ageDays = (int) ($fields['age_days'] ?? 0);
        unset($fields['age_days']);

        $row = [
            'id' => Uuid::v4()->toRfc4122(),
            'schedule_id' => Uuid::v4()->toRfc4122(),
            'club_id' => Uuid::v4()->toRfc4122(),
            'status' => 'COMPLETED',
            'wall_time_ms' => null,
            'nb_teams' => null,
            'nb_venues' => null,
            'queued_at' => null,
            'solve_started_at' => null,
            'payload_bytes' => null,
            'total_wall_time_ms' => null,
            'budget_seconds' => null,
            'solver_status_detail' => null,
            'peak_rss_mb' => null,
            'rss_before_mb' => null,
            'engine_wait_ms' => null,
            ...$fields,
        ];

        $this->admin->executeStatement(<<<'SQL'
            INSERT INTO solver_metrics
                (id, schedule_id, club_id, status, wall_time_ms, nb_teams, nb_venues, queued_at, solve_started_at,
                 payload_bytes, total_wall_time_ms, budget_seconds, solver_status_detail, peak_rss_mb, rss_before_mb,
                 engine_wait_ms, created_at)
            VALUES
                (:id, :schedule_id, :club_id, :status, :wall_time_ms, :nb_teams, :nb_venues, :queued_at, :solve_started_at,
                 :payload_bytes, :total_wall_time_ms, :budget_seconds, :solver_status_detail, :peak_rss_mb, :rss_before_mb,
                 :engine_wait_ms, NOW() - make_interval(days => :age_days))
            SQL, [...$row, 'age_days' => $ageDays]);
    }
}
