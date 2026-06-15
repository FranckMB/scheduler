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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Integration test for schedule generation end-to-end flow.
 *
 * Covers:
 * 1. Create club, season, venue, team, schedule via EM
 * 2. POST /api/schedules/{id}/generate → 202 Accepted
 * 3. Manually invoke GenerateScheduleHandler with mocked engine
 * 4. Verify schedule status transitions to 'done'
 * 5. Verify ScheduleSlotTemplate entities are created
 */
final class ScheduleGenerationTest extends KernelTestCase
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
    public function testScheduleGenerationEndToEnd(): void
    {
        $container = self::getContainer();

        // Mock Mercure hub to avoid external dependency
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'update-id';
        });
        $container->set(HubInterface::class, $hub);

        // Mock ClubGenerationLock to avoid Redis dependency
        $lock = $this->createMock(ClubGenerationLock::class);
        $lock->method('acquire')->willReturn('test-lock-token');
        $lock->method('release');
        $container->set(ClubGenerationLock::class, $lock);

        // ============================================================
        // STEP 1: Create prerequisite entities
        // ============================================================
        $club = new Club();
        $club->setName('Generation Test Club');
        $club->setSlug('generation-test-club-'.uniqid());
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
        $sportCategory->setName('basket');
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

        $team = new Team();
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($sportCategory->getId());
        $team->setPriorityTierId($priorityTier->getId());
        $team->setName('U11 Mixed');
        $team->setSessionsPerWeek(2);
        $team->setGender('mixed');
        $this->em->persist($team);
        $this->em->flush();
        $teamId = $team->getId();

        $coach = new Coach();
        $coach->setClubId($clubId);
        $coach->setSeasonId($seasonId);
        $coach->setFirstName('Jean');
        $coach->setLastName('Dupont');
        $coach->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();
        $coachId = $coach->getId();

        // Add venue availability so the constraint builder has data
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
        $schedule->setName('Test Schedule');
        $schedule->setStatus('draft');
        $this->em->persist($schedule);
        $this->em->flush();
        $scheduleId = $schedule->getId();

        self::assertNotEmpty($scheduleId, 'Schedule should have an ID');
        self::assertSame('draft', $schedule->getStatus(), 'Initial schedule status should be draft');

        // ============================================================
        // STEP 3: Call POST /api/schedules/{id}/generate → 202 Accepted
        // ============================================================
        $generateRequest = Request::create(
            sprintf('/api/schedules/%s/generate', $scheduleId),
            'POST'
        );
        $generateResponse = self::$kernel->handle($generateRequest);

        self::assertSame(202, $generateResponse->getStatusCode(), 'Generation trigger should return 202 Accepted');

        $generateData = json_decode((string) $generateResponse->getContent(), true);
        self::assertSame('Schedule generation queued', $generateData['message']);

        // ============================================================
        // STEP 4: Manually invoke handler with mocked engine response
        // ============================================================
        $engineResponse = new MockResponse(json_encode([
            'status' => 'completed',
            'score' => 9500,
            'metrics' => [
                'solver_version' => '1.0.0',
                'constraint_version' => '1.0.0',
                'score_formula_version' => '1.0.0',
                'nb_variables' => 120,
                'nb_constraints' => 80,
                'nb_conflicts' => 0,
                'wall_time_ms' => 1500,
            ],
            'slots' => [
                '11111111-1111-1111-1111-111111111111' => [
                    'id' => '11111111-1111-1111-1111-111111111111',
                    'teamId' => $teamId,
                    'venueId' => $venueId,
                    'coachId' => $coachId,
                    'dayOfWeek' => 1,
                    'startTime' => '18:00',
                    'durationMinutes' => 90,
                    'lockLevel' => 'NONE',
                ],
                '22222222-2222-2222-2222-222222222222' => [
                    'id' => '22222222-2222-2222-2222-222222222222',
                    'teamId' => $teamId,
                    'venueId' => $venueId,
                    'dayOfWeek' => 3,
                    'startTime' => '15:00',
                    'durationMinutes' => 120,
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
        // STEP 5: Verify schedule status and slots
        // ============================================================
        $this->em->clear();
        $updatedSchedule = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertNotNull($updatedSchedule, 'Schedule should still exist after generation');
        self::assertSame('done', $updatedSchedule->getStatus(), 'Schedule status should be done after successful generation');
        self::assertSame(9500, $updatedSchedule->getScore());
        self::assertSame('1.0.0', $updatedSchedule->getSolverVersion());
        self::assertSame(120, $updatedSchedule->getSolverNbVariables());
        self::assertSame(80, $updatedSchedule->getSolverNbConstraints());
        self::assertSame(0, $updatedSchedule->getSolverNbConflicts());
        self::assertSame(1500, $updatedSchedule->getSolverWallTimeMs());

        // Verify schedule slots were created
        $slots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $scheduleId,
        ]);
        self::assertCount(2, $slots, 'Two slots should have been imported');

        // Verify slot data integrity
        $slotByDay = [];
        foreach ($slots as $slot) {
            $slotByDay[$slot->getDayOfWeek()] = $slot;
        }

        self::assertArrayHasKey(1, $slotByDay, 'Should have a slot on day 1 (Monday)');
        self::assertArrayHasKey(3, $slotByDay, 'Should have a slot on day 3 (Wednesday)');

        $mondaySlot = $slotByDay[1];
        self::assertSame($teamId, $mondaySlot->getTeamId());
        self::assertSame($venueId, $mondaySlot->getVenueId());
        self::assertSame($coachId, $mondaySlot->getCoachId());
        self::assertSame(90, $mondaySlot->getDurationMinutes());

        $wednesdaySlot = $slotByDay[3];
        self::assertSame($teamId, $wednesdaySlot->getTeamId());
        self::assertSame($venueId, $wednesdaySlot->getVenueId());
        self::assertSame(120, $wednesdaySlot->getDurationMinutes());

        // Verify Mercure update was published
        self::assertCount(1, $this->publishedUpdates, 'One Mercure update should have been published');
        $mercureData = json_decode($this->publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('done', $mercureData['status']);
        self::assertSame(9500, $mercureData['score']);
    }

    /** @group phase1 */
    public function testGenerateNonexistentScheduleReturns404(): void
    {
        $request = Request::create('/api/schedules/nonexistent-id/generate', 'POST');
        $response = self::$kernel->handle($request);

        self::assertSame(404, $response->getStatusCode(), 'Generating a nonexistent schedule should return 404');
    }

    /** @group phase1 */
    public function testGenerateScheduleWithWrongClubReturns403(): void
    {
        $container = self::getContainer();

        // Create a club and schedule
        $club = new Club();
        $club->setName('Wrong Club Test');
        $club->setSlug('wrong-club-test-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA'.strtoupper(substr(md5(uniqid()), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $season = new Season();
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        $schedule = new Schedule();
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Wrong Club Schedule');
        $schedule->setStatus('draft');
        $this->em->persist($schedule);
        $this->em->flush();
        $scheduleId = $schedule->getId();

        // Request with a different club ID in the header
        $request = Request::create(
            sprintf('/api/schedules/%s/generate', $scheduleId),
            'POST',
            [],
            [],
            [],
            ['HTTP_X-Club-Id' => '99999999-9999-4999-9999-999999999999']
        );
        $response = self::$kernel->handle($request);

        self::assertSame(403, $response->getStatusCode(), 'Generating a schedule belonging to a different club should return 403');
    }
}
