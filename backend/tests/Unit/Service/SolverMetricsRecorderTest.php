<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Schedule;
use App\Entity\SolverMetric;
use App\Enum\ScheduleStatus;
use App\Service\SolverMetricsRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SolverMetricsRecorderTest extends TestCase
{
    public function testRecordsEngineMetricsAndScheduleStatus(): void
    {
        $schedule = (new Schedule)
            ->setClubId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->setSeasonId('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->setName('metrics')
            ->setStatus(ScheduleStatus::COMPLETED)
            ->setScore(742);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric): bool {
                self::assertSame('COMPLETED', $metric->getStatus());
                self::assertSame(1200, $metric->getWallTimeMs());
                self::assertSame(42, $metric->getNbVariables());
                self::assertSame(80, $metric->getNbConstraints());
                self::assertSame(3, $metric->getNbConflicts());
                self::assertSame(742, $metric->getScore());
                self::assertSame('solver-1', $metric->getSolverVersion());

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
            ->setName('infeasible metrics')
            ->setStatus(ScheduleStatus::FAILED);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static function (SolverMetric $metric): bool {
                self::assertSame('INFEASIBLE', $metric->getStatus());

                return true;
            },
        ));

        new SolverMetricsRecorder($entityManager)->record($schedule, ['status' => 'infeasible']);
    }
}
