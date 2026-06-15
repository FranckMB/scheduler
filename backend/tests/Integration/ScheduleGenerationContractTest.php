<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueAvailability;
use App\Enum\LockLevel;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleResultImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Contract-level integration test for the schedule generation solver fix.
 *
 * Verifies:
 * 1. Engine response with metrics + unplaced is processed correctly by the backend handler.
 * 2. All contract fields (status, score, slots, metrics, unplaced, diagnostics) are persisted.
 * 3. Slots are saved to the database and queryable.
 * 4. Schedule status transitions correctly (done / partial) based on unplaced count.
 */
final class ScheduleGenerationContractTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    /** @var list<Update> */
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->publishedUpdates = [];
    }

    protected function tearDown(): void
    {
        if (null !== $this->em) {
            $this->em->getConnection()->rollBack();
            $this->em->close();
            $this->em = null;
        }

        $this->publishedUpdates = [];
        parent::tearDown();
    }

    /** @group phase1 */
    public function testEngineContractMetricsAndUnplacedArePersistedCorrectly(): void
    {
        $container = self::getContainer();

        // Mock Mercure hub
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });
        $container->set(HubInterface::class, $hub);

        // Mock ClubGenerationLock
        $lock = $this->createMock(ClubGenerationLock::class);
        $lock->method('acquire')->willReturn('test-lock-token');
        $lock->method('release');
        $container->set(ClubGenerationLock::class, $lock);

        // ============================================================
        // STEP 1: Create prerequisite entities
        // ============================================================
        $club = new Club();
        $club->setName('Contract Test Club');
        $club->setSlug('contract-test-club-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA'.strtoupper(substr(md5(uniqid()), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();

        $season = new Season();
        $season->setClubId($clubId);
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $sport = new Sport();
        $sport->setName('Basketball');
        $sport->setSlug('basketball-'.uniqid());
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $sportCategory = new SportCategory();
        $sportCategory->setClubId($clubId);
        $sportCategory->setSportId($sport->getId());
        $sportCategory->setName('U13');
        $sportCategory->setIsCustom(false);
        $sportCategory->setSortOrder(1);
        $this->em->persist($sportCategory);
        $this->em->flush();

        $priorityTier = new PriorityTier();
        $priorityTier->setId(1);
        $priorityTier->setLabel('S');
        $priorityTier->setName('Senior');
        $priorityTier->setColor('#FF0000');
        $priorityTier->setOrToolsWeight(100);
        $priorityTier->setDefaultMinSessions(2);
        $this->em->persist($priorityTier);
        $this->em->flush();

        $venue = new Venue();
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Main Hall');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $venueId = $venue->getId();

        $teamA = new Team();
        $teamA->setClubId($clubId);
        $teamA->setSeasonId($seasonId);
        $teamA->setSportCategoryId($sportCategory->getId());
        $teamA->setPriorityTierId($priorityTier->getId());
        $teamA->setName('Team A');
        $teamA->setSessionsPerWeek(2);
        $teamA->setGender('mixed');
        $this->em->persist($teamA);
        $this->em->flush();
        $teamAId = $teamA->getId();

        $teamB = new Team();
        $teamB->setClubId($clubId);
        $teamB->setSeasonId($seasonId);
        $teamB->setSportCategoryId($sportCategory->getId());
        $teamB->setPriorityTierId($priorityTier->getId());
        $teamB->setName('Team B');
        $teamB->setSessionsPerWeek(2);
        $teamB->setGender('mixed');
        $this->em->persist($teamB);
        $this->em->flush();
        $teamBId = $teamB->getId();

        $coach = new Coach();
        $coach->setClubId($clubId);
        $coach->setSeasonId($seasonId);
        $coach->setFirstName('Jean');
        $coach->setLastName('Dupont');
        $coach->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();
        $coachId = $coach->getId();

        $availability = new VenueAvailability();
        $availability->setClubId($clubId);
        $availability->setSeasonId($seasonId);
        $availability->setVenueId($venueId);
        $availability->setDayOfWeek(1);
        $availability->setStartTime(new \DateTimeImmutable('18:00'));
        $availability->setEndTime(new \DateTimeImmutable('22:00'));
        $this->em->persist($availability);
        $this->em->flush();

        // ============================================================
        // STEP 2: Create schedule
        // ============================================================
        $schedule = new Schedule();
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setName('Contract Test Schedule');
        $schedule->setStatus('draft');
        $this->em->persist($schedule);
        $this->em->flush();
        $scheduleId = $schedule->getId();

        // ============================================================
        // STEP 3: Mock engine response with FULL contract fields
        // ============================================================
        $engineResponse = new MockResponse(json_encode([
            'status' => 'completed',
            'score' => 12345,
            'metrics' => [
                'solver_version' => 'ortools-9.11',
                'constraint_version' => 'v1',
                'score_formula_version' => 'T24',
                'nb_variables' => 256,
                'nb_constraints' => 128,
                'nb_conflicts' => 0,
                'wall_time_ms' => 2500,
            ],
            'slots' => [
                [
                    'id' => '11111111-1111-1111-1111-111111111111',
                    'teamId' => $teamAId,
                    'venueId' => $venueId,
                    'coachId' => $coachId,
                    'dayOfWeek' => 1,
                    'startTime' => '18:00',
                    'durationMinutes' => 90,
                    'lockLevel' => 'NONE',
                ],
                [
                    'id' => '22222222-2222-2222-2222-222222222222',
                    'teamId' => $teamAId,
                    'venueId' => $venueId,
                    'coachId' => $coachId,
                    'dayOfWeek' => 1,
                    'startTime' => '19:45',
                    'durationMinutes' => 90,
                    'lockLevel' => 'NONE',
                ],
                [
                    'id' => '33333333-3333-3333-3333-333333333333',
                    'teamId' => $teamBId,
                    'venueId' => $venueId,
                    'coachId' => $coachId,
                    'dayOfWeek' => 1,
                    'startTime' => '21:30',
                    'durationMinutes' => 60,
                    'lockLevel' => 'NONE',
                ],
            ],
            'unplaced' => [],
            'diagnostics' => [],
            'warnings' => [],
        ], JSON_THROW_ON_ERROR));

        $mockHttpClient = new MockHttpClient($engineResponse);

        $handler = new GenerateScheduleHandler(
            $this->em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            $mockHttpClient,
            $hub,
            $lock,
            $container->get(DiagnosticMessageBuilder::class),
        );

        $message = new GenerateScheduleMessage($scheduleId, $clubId);
        $handler($message);

        // ============================================================
        // STEP 4: Verify schedule entity has all contract fields
        // ============================================================
        $this->em->clear();
        $updatedSchedule = $this->em->getRepository(Schedule::class)->find($scheduleId);

        self::assertNotNull($updatedSchedule);
        self::assertSame('done', $updatedSchedule->getStatus(), 'Status should be done when unplaced is empty');
        self::assertSame(12345, $updatedSchedule->getScore());

        // Metrics
        self::assertSame('ortools-9.11', $updatedSchedule->getSolverVersion());
        self::assertSame('v1', $updatedSchedule->getConstraintVersion());
        self::assertSame('T24', $updatedSchedule->getScoreFormulaVersion());
        self::assertSame(256, $updatedSchedule->getSolverNbVariables());
        self::assertSame(128, $updatedSchedule->getSolverNbConstraints());
        self::assertSame(0, $updatedSchedule->getSolverNbConflicts());
        self::assertSame(2500, $updatedSchedule->getSolverWallTimeMs());

        // ============================================================
        // STEP 5: Verify slots are saved to database
        // ============================================================
        $slots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $scheduleId,
        ]);
        self::assertCount(3, $slots, 'Three slots should have been imported');

        $slotIds = array_map(static fn (ScheduleSlotTemplate $s) => $s->getId(), $slots);
        self::assertContains('11111111-1111-1111-1111-111111111111', $slotIds);
        self::assertContains('22222222-2222-2222-2222-222222222222', $slotIds);
        self::assertContains('33333333-3333-3333-3333-333333333333', $slotIds);

        // Verify slot field integrity
        $slotMap = [];
        foreach ($slots as $slot) {
            $slotMap[$slot->getId()] = $slot;
        }

        self::assertSame($teamAId, $slotMap['11111111-1111-1111-1111-111111111111']->getTeamId());
        self::assertSame($venueId, $slotMap['11111111-1111-1111-1111-111111111111']->getVenueId());
        self::assertSame($coachId, $slotMap['11111111-1111-1111-1111-111111111111']->getCoachId());
        self::assertSame(1, $slotMap['11111111-1111-1111-1111-111111111111']->getDayOfWeek());
        self::assertSame(90, $slotMap['11111111-1111-1111-1111-111111111111']->getDurationMinutes());
        self::assertSame(LockLevel::NONE, $slotMap['11111111-1111-1111-1111-111111111111']->getLockLevel());

        self::assertSame(LockLevel::NONE, $slotMap['33333333-3333-3333-3333-333333333333']->getLockLevel());

        // ============================================================
        // STEP 6: Verify Mercure update was published with correct data
        // ============================================================
        self::assertCount(1, $this->publishedUpdates);
        $mercureData = json_decode($this->publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('done', $mercureData['status']);
        self::assertSame(12345, $mercureData['score']);
        self::assertSame(0, $mercureData['unplaced']);
    }

    /** @group phase1 */
    public function testPartialGenerationWithUnplacedTeamsMarksPartialStatus(): void
    {
        $container = self::getContainer();

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });
        $container->set(HubInterface::class, $hub);

        $lock = $this->createMock(ClubGenerationLock::class);
        $lock->method('acquire')->willReturn('test-lock-token');
        $lock->method('release');
        $container->set(ClubGenerationLock::class, $lock);

        // Create minimal club data
        $club = new Club();
        $club->setName('Partial Test Club');
        $club->setSlug('partial-test-club-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA'.strtoupper(substr(md5(uniqid()), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();

        $season = new Season();
        $season->setClubId($clubId);
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $sport = new Sport();
        $sport->setName('Basketball');
        $sport->setSlug('basketball-'.uniqid());
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $sportCategory = new SportCategory();
        $sportCategory->setClubId($clubId);
        $sportCategory->setSportId($sport->getId());
        $sportCategory->setName('U13');
        $sportCategory->setIsCustom(false);
        $sportCategory->setSortOrder(1);
        $this->em->persist($sportCategory);
        $this->em->flush();

        $priorityTier = new PriorityTier();
        $priorityTier->setId(1);
        $priorityTier->setLabel('S');
        $priorityTier->setName('Senior');
        $priorityTier->setColor('#FF0000');
        $priorityTier->setOrToolsWeight(100);
        $priorityTier->setDefaultMinSessions(2);
        $this->em->persist($priorityTier);
        $this->em->flush();

        $venue = new Venue();
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Main Hall');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $venueId = $venue->getId();

        $teamA = new Team();
        $teamA->setClubId($clubId);
        $teamA->setSeasonId($seasonId);
        $teamA->setSportCategoryId($sportCategory->getId());
        $teamA->setPriorityTierId($priorityTier->getId());
        $teamA->setName('Team A');
        $teamA->setSessionsPerWeek(2);
        $teamA->setGender('mixed');
        $this->em->persist($teamA);
        $this->em->flush();
        $teamAId = $teamA->getId();

        $teamB = new Team();
        $teamB->setClubId($clubId);
        $teamB->setSeasonId($seasonId);
        $teamB->setSportCategoryId($sportCategory->getId());
        $teamB->setPriorityTierId($priorityTier->getId());
        $teamB->setName('Team B');
        $teamB->setSessionsPerWeek(2);
        $teamB->setGender('mixed');
        $this->em->persist($teamB);
        $this->em->flush();
        $teamBId = $teamB->getId();

        $availability = new VenueAvailability();
        $availability->setClubId($clubId);
        $availability->setSeasonId($seasonId);
        $availability->setVenueId($venueId);
        $availability->setDayOfWeek(1);
        $availability->setStartTime(new \DateTimeImmutable('18:00'));
        $availability->setEndTime(new \DateTimeImmutable('19:00'));
        $this->em->persist($availability);
        $this->em->flush();

        $schedule = new Schedule();
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setName('Partial Test Schedule');
        $schedule->setStatus('draft');
        $this->em->persist($schedule);
        $this->em->flush();
        $scheduleId = $schedule->getId();

        // Engine returns completed but with unplaced teams
        $engineResponse = new MockResponse(json_encode([
            'status' => 'completed',
            'score' => 5000,
            'metrics' => [
                'solver_version' => 'ortools-9.11',
                'nb_variables' => 64,
                'nb_constraints' => 32,
                'wall_time_ms' => 800,
            ],
            'slots' => [
                [
                    'id' => '44444444-4444-4444-4444-444444444444',
                    'teamId' => $teamAId,
                    'venueId' => $venueId,
                    'dayOfWeek' => 1,
                    'startTime' => '18:00',
                    'durationMinutes' => 60,
                    'lockLevel' => 'NONE',
                ],
            ],
            'unplaced' => [$teamBId],
            'diagnostics' => [
                [
                    'type' => 'unplaced',
                    'severity' => 'high',
                    'teamId' => $teamBId,
                    'message' => 'Team B could not be placed.',
                    'suggestions' => ['Add more venue availability.'],
                ],
            ],
            'warnings' => ['tight constraints'],
        ], JSON_THROW_ON_ERROR));

        $mockHttpClient = new MockHttpClient($engineResponse);

        $handler = new GenerateScheduleHandler(
            $this->em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            $mockHttpClient,
            $hub,
            $lock,
            $container->get(DiagnosticMessageBuilder::class),
        );

        $message = new GenerateScheduleMessage($scheduleId, $clubId);
        $handler($message);

        $this->em->clear();
        $updatedSchedule = $this->em->getRepository(Schedule::class)->find($scheduleId);

        // When unplaced is non-empty, status should be 'partial'
        self::assertSame('partial', $updatedSchedule->getStatus());
        self::assertSame(5000, $updatedSchedule->getScore());

        // Metrics should still be persisted
        self::assertSame('ortools-9.11', $updatedSchedule->getSolverVersion());
        self::assertSame(64, $updatedSchedule->getSolverNbVariables());
        self::assertSame(32, $updatedSchedule->getSolverNbConstraints());
        self::assertSame(800, $updatedSchedule->getSolverWallTimeMs());

        // Only 1 slot imported
        $slots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $scheduleId,
        ]);
        self::assertCount(1, $slots);

        // Mercure update should report unplaced count
        self::assertCount(1, $this->publishedUpdates);
        $mercureData = json_decode($this->publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial', $mercureData['status']);
        self::assertSame(5000, $mercureData['score']);
        self::assertSame(1, $mercureData['unplaced']);
    }
}
