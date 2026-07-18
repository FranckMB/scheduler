<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Schedule;
use App\Entity\SolverMetric;
use App\Enum\ScheduleStatus;
use App\Service\SolverMetricsRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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
        $entityManager = $this->em(planType: 'CLOSURE', activeCount: 12);
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
                self::assertSame(12, $metric->getNbVenues());

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

    public function testPreservesInfeasibleEngineOutcomeInMetricsHistory(): void
    {
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setSchedulePlanId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->setName('infeasible metrics')
            ->setStatus(ScheduleStatus::FAILED);
        $entityManager = $this->em(planType: 'SEASON', activeCount: 0);
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

    /** @return EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function em(string $planType, int $activeCount): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($planType);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn($activeCount);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturn($repository);

        return $entityManager;
    }
}
