<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\Team;
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
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * @group phase1
 */
final class GenerateScheduleHandlerDiagnosticsTest extends TestCase
{
    private const SCHEDULE_ID = '11111111-1111-4111-8111-111111111111';
    private const CLUB_ID = '22222222-2222-4222-8222-222222222222';
    private const SEASON_ID = '33333333-3333-4333-8333-333333333333';
    private const TEAM_ID = '44444444-4444-4444-8444-444444444444';
    private const COACH_ID = '66666666-6666-4666-8666-666666666666';
    private const VENUE_ID = '55555555-5555-4555-8555-555555555555';

    public function testUnplacedDiagnosticIsTransformedToFrenchBusinessMessageWithTeamName(): void
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
                'status' => 'failed',
                'diagnostics' => [[
                    'type' => 'unplaced',
                    'severity' => 'high',
                    'team_id' => self::TEAM_ID,
                    'message' => 'Team 44444444-4444-4444-8444-444444444444 could not be placed in the schedule. No available slot matched the team\'s constraints.',
                    'suggestions' => ['Add more venue availability.'],
                ]],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertSame('unplaced', $persisted[0]->getType());
        self::assertSame(
            'U13 M3 n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.',
            $persisted[0]->getMessage(),
        );
        self::assertSame(self::TEAM_ID, $persisted[0]->getTeamId());
    }

    public function testConflictVenueDiagnosticIsTransformedToFrenchBusinessMessageWithVenueName(): void
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
                'status' => 'failed',
                'diagnostics' => [[
                    'type' => 'conflict',
                    'severity' => 'high',
                    'venue_id' => self::VENUE_ID,
                    'message' => 'Venue 55555555-5555-4555-8555-555555555555 is double-booked on day 2 at 18:00 for teams 44444444-4444-4444-8444-444444444444.',
                    'suggestions' => ['Move one session.'],
                ]],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertSame('conflict', $persisted[0]->getType());
        self::assertSame(
            'Salle B accueille plusieurs équipes simultanément. Veuillez déplacer l\'une des séances.',
            $persisted[0]->getMessage(),
        );
        self::assertSame(self::VENUE_ID, $persisted[0]->getVenueId());
    }

    public function testConflictCoachDiagnosticIsTransformedToFrenchBusinessMessageWithCoachName(): void
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
                'status' => 'failed',
                'diagnostics' => [[
                    'type' => 'conflict',
                    'severity' => 'high',
                    'coach_id' => self::COACH_ID,
                    'message' => 'Coach 66666666-6666-4666-8666-666666666666 is assigned to multiple teams on day 2 at 18:00.',
                    'suggestions' => ['Split the sessions.'],
                ]],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertSame('conflict', $persisted[0]->getType());
        self::assertSame(
            'Coach Martin est assigné(e) à plusieurs équipes simultanément. Veuillez réattribuer l\'une des séances.',
            $persisted[0]->getMessage(),
        );
        self::assertSame(self::COACH_ID, $persisted[0]->getCoachId());
    }

    public function testCoachOverloadDiagnosticIsTransformedToFrenchBusinessMessageWithCoachName(): void
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
                'status' => 'failed',
                'diagnostics' => [[
                    'type' => 'coach_overload',
                    'severity' => 'medium',
                    'coach_id' => self::COACH_ID,
                    'count' => 7,
                    'threshold' => 5,
                    'message' => 'Coach 66666666-6666-4666-8666-666666666666 is assigned 7 sessions, which is above the recommended limit of 5.',
                    'suggestions' => ['Redistribute sessions.'],
                ]],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertSame('coach_overload', $persisted[0]->getType());
        self::assertSame(
            'Coach Martin est surchargé(e) avec 7 séances (limite recommandée : 5).',
            $persisted[0]->getMessage(),
        );
        self::assertSame(self::COACH_ID, $persisted[0]->getCoachId());
    }

    public function testSoftLockMovedDiagnosticIsTransformedToFrenchBusinessMessageWithTeamAndVenueNames(): void
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
                'status' => 'failed',
                'diagnostics' => [[
                    'type' => 'soft_lock_moved',
                    'severity' => 'medium',
                    'team_id' => self::TEAM_ID,
                    'venue_id' => self::VENUE_ID,
                    'message' => 'The preferred slot for team U13 M3 at Salle B was moved.',
                    'suggestions' => ['Review the new time.'],
                ]],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertSame('soft_lock_moved', $persisted[0]->getType());
        self::assertSame(
            'Le créneau préféré de U13 M3 (Salle B) a été déplacé par le solveur pour un meilleur ajustement global.',
            $persisted[0]->getMessage(),
        );
        self::assertSame(self::TEAM_ID, $persisted[0]->getTeamId());
        self::assertSame(self::VENUE_ID, $persisted[0]->getVenueId());
    }

    public function testMultipleDiagnosticsAreAllTransformedToFrenchBusinessMessages(): void
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
                'status' => 'failed',
                'diagnostics' => [
                    [
                        'type' => 'unplaced',
                        'severity' => 'high',
                        'team_id' => self::TEAM_ID,
                        'message' => 'Team could not be placed.',
                        'suggestions' => [],
                    ],
                    [
                        'type' => 'coach_overload',
                        'severity' => 'medium',
                        'coach_id' => self::COACH_ID,
                        'count' => 7,
                        'threshold' => 5,
                        'message' => 'Coach overloaded.',
                        'suggestions' => [],
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(2, $persisted);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[0]);
        self::assertInstanceOf(ScheduleDiagnostic::class, $persisted[1]);

        $messages = array_map(static fn (ScheduleDiagnostic $d): string => $d->getMessage(), $persisted);
        self::assertContains('U13 M3 n\'a pas pu être placée dans le planning : aucun créneau ne correspondait à ses contraintes.', $messages);
        self::assertContains('Coach Martin est surchargé(e) avec 7 séances (limite recommandée : 5).', $messages);
    }

    public function testNoTechnicalTermsAppearInTransformedMessages(): void
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
                'status' => 'failed',
                'diagnostics' => [[
                    'type' => 'unplaced',
                    'severity' => 'high',
                    'team_id' => self::TEAM_ID,
                    'message' => 'variable forced to 0, infeasible constraint set',
                    'suggestions' => [],
                ]],
            ], JSON_THROW_ON_ERROR))),
            $this->hub($publishedUpdates),
            $this->clubGenerationLock(),
            new DiagnosticMessageBuilder(),
        );

        $handler(new GenerateScheduleMessage(self::SCHEDULE_ID, self::CLUB_ID));

        self::assertSame('failed', $schedule->getStatus());
        self::assertCount(1, $persisted);
        $message = $persisted[0]->getMessage();
        self::assertStringNotContainsString('variable', $message);
        self::assertStringNotContainsString('forced to 0', $message);
        self::assertStringNotContainsString('infeasible', $message);
        self::assertStringNotContainsString('constraint', $message);
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

    private function team(): Team
    {
        return (new Team())
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setId(self::TEAM_ID)
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')
            ->setPriorityTierId(1)
            ->setName('U13 M3')
            ->setSessionsPerWeek(2);
    }

    private function coach(): Coach
    {
        return (new Coach())
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setId(self::COACH_ID)
            ->setFirstName('Coach')
            ->setLastName('Martin')
            ->setEmail('coach@example.com');
    }

    private function venue(): Venue
    {
        return (new Venue())
            ->setClubId(self::CLUB_ID)
            ->setSeasonId(self::SEASON_ID)
            ->setId(self::VENUE_ID)
            ->setName('Salle B')
            ->setSource('manual');
    }

    private function entityManager(Schedule $schedule, array &$persisted): EntityManagerInterface&MockObject
    {
        $repositories = [
            Schedule::class => $this->repository($schedule),
            Team::class => $this->repository([$this->team()]),
            Coach::class => $this->repository([$this->coach()]),
            Venue::class => $this->repository([$this->venue()]),
            \App\Entity\TeamConstraint::class => $this->repository([]),
            \App\Entity\VenueAvailability::class => $this->repository([]),
            \App\Entity\CoachUnavailability::class => $this->repository([]),
            \App\Entity\TeamCoach::class => $this->repository([]),
            \App\Entity\CoachPlayerMembership::class => $this->repository([]),
            \App\Entity\ScheduleSlotTemplate::class => $this->repository([]),
            \App\Entity\PriorityTier::class => $this->repository([]),
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
