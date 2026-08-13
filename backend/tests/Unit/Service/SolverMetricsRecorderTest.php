<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Schedule;
use App\Entity\SolverMetric;
use App\Entity\Team;
use App\Enum\ScheduleStatus;
use App\Service\SolverMetricsRecorder;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SolverMetricsRecorderTest extends TestCase
{
    public function testRecordsEngineMetricsAndScheduleStatus(): void
    {
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setSchedulePlanId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->setName('metrics')
            ->setStatus(ScheduleStatus::COMPLETED)
            ->setScore(742);
        // Comptes DIFFÉRENCIÉS par entité : un swap Team/Venue dans le recorder doit rougir.
        $entityManager = $this->em(planType: 'CLOSURE', teamCount: 12, venueCount: 3);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric): bool {
                self::assertSame('COMPLETED', $metric->getStatus());
                self::assertSame(1200, $metric->getWallTimeMs());
                self::assertSame(42, $metric->getNbVariables());
                self::assertSame(80, $metric->getNbConstraints());
                self::assertSame(3, $metric->getNbConflicts());
                self::assertSame(742, $metric->getScore());
                self::assertSame('solver-1', $metric->getSolverVersion());
                // SA2-stats : dimensions dénormalisées à la capture (append-only).
                self::assertSame('CLOSURE', $metric->getPlanType());
                self::assertSame(12, $metric->getNbTeams());
                self::assertSame(3, $metric->getNbVenues());

                return true;
            },
        ));

        $recorder = new SolverMetricsRecorder($entityManager);
        $recorder->record($schedule, ['metrics' => [
            'wall_time_ms' => 1200,
            'nb_variables' => 42,
            'nb_constraints' => 80,
            'nb_conflicts' => 3,
            'solver_version' => 'solver-1',
        ]]);
    }

    public function testCapturesCapacityMetricsAndScheduleTimestamps(): void
    {
        // P5-10 — les métriques de capacité de l'engine + les instants de cycle de vie
        // (copiés du Schedule) + la taille du payload (paramètre) sont capturés.
        $queuedAt = new DateTimeImmutable('2026-08-13 10:00:00');
        $solveStartedAt = new DateTimeImmutable('2026-08-13 10:00:05');
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setSchedulePlanId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->setName('capacity')
            ->setStatus(ScheduleStatus::COMPLETED)
            ->setScore(500)
            ->setQueuedAt($queuedAt)
            ->setSolveStartedAt($solveStartedAt);
        $entityManager = $this->em(planType: 'SEASON', teamCount: 20, venueCount: 4);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric) use ($queuedAt, $solveStartedAt): bool {
                self::assertSame($queuedAt, $metric->getQueuedAt());
                self::assertSame($solveStartedAt, $metric->getSolveStartedAt());
                self::assertSame(48123, $metric->getPayloadBytes());
                self::assertSame(600123, $metric->getTotalWallTimeMs());
                self::assertSame(1200000, $metric->getCpuTimeMs());
                self::assertSame(8, $metric->getWorkers());
                self::assertSame(600, $metric->getBudgetSeconds());
                self::assertSame('OPTIMAL', $metric->getSolverStatusDetail());
                self::assertSame(512.5, $metric->getPeakRssMb());
                self::assertSame(128.25, $metric->getRssBeforeMb());
                self::assertSame(900, $metric->getEngineWaitMs());

                return true;
            },
        ));

        new SolverMetricsRecorder($entityManager)->record($schedule, ['metrics' => [
            'total_wall_time_ms' => 600123,
            'cpu_time_ms' => 1200000,
            'workers' => 8,
            'budget_seconds' => 600,
            'solver_status_detail' => 'OPTIMAL',
            'peak_rss_mb' => 512.5,
            'rss_before_mb' => 128.25,
            'engine_wait_ms' => 900,
        ]], payloadBytes: 48123);
    }

    public function testCapacityMetricsAreNullWhenEngineSentNone(): void
    {
        // Chemin terminal d'échec : pas de metrics engine, pas de payloadBytes → tout null,
        // la ligne s'écrit quand même (les anciens chemins terminaux continuent de marcher).
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setSchedulePlanId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->setName('failed no metrics')
            ->setStatus(ScheduleStatus::FAILED);
        $entityManager = $this->em(planType: 'SEASON', teamCount: 0, venueCount: 0);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric): bool {
                self::assertNull($metric->getQueuedAt());
                self::assertNull($metric->getSolveStartedAt());
                self::assertNull($metric->getPayloadBytes());
                self::assertNull($metric->getTotalWallTimeMs());
                self::assertNull($metric->getCpuTimeMs());
                self::assertNull($metric->getWorkers());
                self::assertNull($metric->getBudgetSeconds());
                self::assertNull($metric->getSolverStatusDetail());
                self::assertNull($metric->getPeakRssMb());
                self::assertNull($metric->getRssBeforeMb());
                self::assertNull($metric->getEngineWaitMs());

                return true;
            },
        ));

        new SolverMetricsRecorder($entityManager)->record($schedule);
    }

    public function testPreservesInfeasibleEngineOutcomeInMetricsHistory(): void
    {
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setSchedulePlanId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->setName('infeasible metrics')
            ->setStatus(ScheduleStatus::FAILED);
        $entityManager = $this->em(planType: 'SEASON', teamCount: 0, venueCount: 0);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric): bool {
                self::assertSame('INFEASIBLE', $metric->getStatus());

                return true;
            },
        ));

        new SolverMetricsRecorder($entityManager)->record($schedule, ['status' => 'infeasible']);
    }

    public function testCaptureIsBestEffortWhenDimensionsCannotBeResolved(): void
    {
        // La capture ne doit JAMAIS faire échouer la génération : plan disparu /
        // lecture en échec → dimensions null, la métrique s'écrit quand même.
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setSchedulePlanId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->setName('vanished plan')
            ->setStatus(ScheduleStatus::COMPLETED);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new RuntimeException('plan vanished'));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willThrowException(new RuntimeException('db down'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric): bool {
                self::assertNull($metric->getPlanType());
                self::assertNull($metric->getNbTeams());
                self::assertNull($metric->getNbVenues());

                return true;
            },
        ));

        new SolverMetricsRecorder($entityManager)->record($schedule);
    }

    /** @return EntityManagerInterface&MockObject */
    private function em(string $planType, int $teamCount, int $venueCount): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($planType);
        $teams = $this->createMock(EntityRepository::class);
        $teams->method('count')->willReturn($teamCount);
        $venues = $this->createMock(EntityRepository::class);
        $venues->method('count')->willReturn($venueCount);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): MockObject => Team::class === $class ? $teams : $venues,
        );

        return $entityManager;
    }
}
