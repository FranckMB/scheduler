<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamConstraint;
use App\Entity\Venue;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleResultImporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class GenerateScheduleHandlerTest extends TestCase
{
    private const SCHEDULE_ID = '11111111-1111-4111-8111-111111111111';
    private const CLUB_ID = '22222222-2222-4222-8222-222222222222';
    private const SEASON_ID = '33333333-3333-4333-8333-333333333333';

    public function testCompletedGenerationImportsSlotsMarksDoneAndPublishesMercureUpdate(): void
    {
        $schedule = $this->schedule();
        $persisted = [];
        $publishedUpdates = [];
        $entityManager = $this->entityManager($schedule, $persisted);

        $handler = new GenerateScheduleHandler(
            $entityManager,
            new ScheduleConstraintBuilder(),
            new ScheduleResultImporter($entityManager),
            new MockHttpClient(new MockResponse(json_encode([
                'status' => 'completed',
                'score' => 94,
                'metrics' => [
                    'solver_version' => 'engine-1',
                    'nb_variables' => 10,
                    'nb_constraints' => 20,
                    'wall_time_ms' => 300,
                ],
                'slots' => [[
                    'id' => '77777777-7777-4777-8777-777777777777',
                    'teamId' => '44444444-4444-4444-8444-444444444444',
                    'venueId' => '55555555-5555-4555-8555-555555555555',
                    'coachId' => '66666666-6666-4666-8666-666666666666',
                    'dayOfWeek' => 2,
                    'startTime' => '18:00',
                    'durationMinutes' => 90,
                ]],
                'unplaced' => [],
                'warnings' => ['low margin'],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID, 60));

        $snapshot = $schedule->getSnapshotData();
        self::assertSame('done', $schedule->getStatus());
        self::assertSame(94, $schedule->getScore());
        self::assertSame(60, $schedule->getSolverTimeoutSeconds());
        self::assertSame(self::CLUB_ID, $snapshot['clubId']);
        self::assertSame(self::SEASON_ID, $snapshot['seasonId']);
        self::assertSame(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), $schedule->getSnapshotHash());
        self::assertSame('engine-1', $schedule->getSolverVersion());
        self::assertSame(10, $schedule->getSolverNbVariables());
        self::assertCount(1, $persisted);
        self::assertContainsOnlyInstancesOf(ScheduleSlotTemplate::class, $persisted);
        self::assertCount(1, $publishedUpdates);
        self::assertSame(sprintf('club:%s:schedule:%s', self::CLUB_ID, self::SCHEDULE_ID), $publishedUpdates[0]->getTopics()[0]);
        self::assertSame([
            'status' => 'done',
            'score' => 94,
            'unplaced' => 0,
            'warnings' => ['low margin'],
        ], json_decode($publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testFailedGenerationPersistsDiagnosticsAndPublishesFailedUpdate(): void
    {
        $schedule = $this->schedule();
        $persisted = [];
        $publishedUpdates = [];
        $entityManager = $this->entityManager($schedule, $persisted);

        $handler = new GenerateScheduleHandler(
            $entityManager,
            new ScheduleConstraintBuilder(),
            new ScheduleResultImporter($entityManager),
            new MockHttpClient(new MockResponse(json_encode([
                'status' => 'infeasible',
                'diagnostics' => [[
                    'type' => 'team_unplaced',
                    'severity' => 'error',
                    'message' => 'Team cannot be placed.',
                    'team_id' => '44444444-4444-4444-8444-444444444444',
                ]],
                'warnings' => ['tight constraints'],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertSame('team_unplaced', $persisted[0]->getType());
        self::assertSame('Team cannot be placed.', $persisted[0]->getMessage());
        self::assertSame('failed', json_decode($publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    public function testTransportTimeoutMarksScheduleTimeoutAndPublishesTimeoutUpdate(): void
    {
        $schedule = $this->schedule();
        $persisted = [];
        $publishedUpdates = [];
        $entityManager = $this->entityManager($schedule, $persisted);

        $handler = new GenerateScheduleHandler(
            $entityManager,
            new ScheduleConstraintBuilder(),
            new ScheduleResultImporter($entityManager),
            new MockHttpClient(static fn (): MockResponse => throw new TransportException('timeout')),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID, 1));

        self::assertSame('timeout', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertSame('engine_timeout', $persisted[0]->getType());
        self::assertSame('timeout', json_decode($publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    private function schedule(): Schedule
    {
        return (new Schedule())
            ->setId(self::SCHEDULE_ID)
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Generated schedule')
            ->setStatus('queued');
    }

    private function venue(): Venue
    {
        return (new Venue())
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setName('Main hall')
            ->setSource('manual');
    }

    private function entityManager(Schedule $schedule, array &$persisted): EntityManagerInterface&MockObject
    {
        $repositories = [
            Schedule::class => $this->repository($schedule),
            Venue::class => $this->repository([$this->venue()]),
            Team::class => $this->repository([]),
            Coach::class => $this->repository([]),
            TeamConstraint::class => $this->repository([]),
            ScheduleSlotTemplate::class => $this->repository([]),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->willReturnCallback(static fn (string $className): EntityRepository => $repositories[$className]);
        $entityManager->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::atLeastOnce())
            ->method('flush');

        return $entityManager;
    }

    private function repository(Schedule|array $result): EntityRepository&MockObject
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($result instanceof Schedule ? $result : null);
        $repository->method('findBy')->willReturn(is_array($result) ? $result : []);

        return $repository;
    }

    private function clubGenerationLock(): ClubGenerationLock&MockObject
    {
        $lock = $this->createMock(ClubGenerationLock::class);
        $lock->expects(self::once())
            ->method('acquire')
            ->with(self::CLUB_ID, self::isType('int'))
            ->willReturn('lock-token');
        $lock->expects(self::once())
            ->method('release')
            ->with(self::CLUB_ID, 'lock-token');

        return $lock;
    }

    /** @param list<Update> $publishedUpdates */
    private function hub(array &$publishedUpdates): HubInterface&MockObject
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(static function (Update $update) use (&$publishedUpdates): bool {
                $publishedUpdates[] = $update;

                return true;
            }))
            ->willReturn('update-id');

        return $hub;
    }
}
