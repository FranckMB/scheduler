<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamConstraint;
use App\Entity\Venue;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Integration test for the 9-step wizard data flow.
 *
 * Covers:
 * 1. Club/Season prerequisite setup
 * 2. Venue creation with can_split=true
 * 3. Team creation with new fields (level, gender, is_competition, size)
 * 4. Preferred slots configuration
 * 5. Fixed constraints
 * 6. Forbidden constraints
 * 7. Schedule creation
 * 8. Generation trigger (mocked engine)
 * 9. Result verification
 */
final class WizardFlowTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private static ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        parent::setUp();

        self::bootKernel();
        $container = self::getContainer();
        self::$entityManager = $container->get(EntityManagerInterface::class);

        $this->createSchema();
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->truncateTables();

        if (null !== self::$entityManager) {
            self::$entityManager->close();
            self::$entityManager = null;
        }

        parent::tearDown();
    }

    private function createSchema(): void
    {
        $schemaTool = new SchemaTool(self::$entityManager);
        $metadata = self::$entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function truncateTables(): void
    {
        $connection = self::$entityManager->getConnection();
        $tables = [
            'schedule_slot_template',
            'schedule_diagnostic',
            'team_constraint',
            'team_coach',
            'coach_player_membership',
            'coach_unavailability',
            'venue_closure',
            'venue_availability',
            'schedule',
            'team',
            'coach',
            'venue',
            'priority_tier',
            'sport_category',
            'sport',
            'season',
            'club_user',
            'club',
        ];

        foreach ($tables as $table) {
            try {
                $connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
            } catch (\Throwable) {
                // Table may not exist, ignore
            }
        }
    }

    /** @group phase1 */
    public function testFullWizardFlow(): void
    {
        $client = self::createClient();

        // ============================================================
        // STEP 1: Create prerequisite entities (Club, Season, Sport, SportCategory, PriorityTier)
        // ============================================================
        $club = $this->createClub();
        $season = $this->createSeason($club);
        $sport = $this->createSport();
        $sportCategory = $this->createSportCategory($sport);
        $priorityTier = $this->createPriorityTier();

        $clubId = $club->getId();
        $seasonId = $season->getId();

        // ============================================================
        // STEP 2: Create Venue via API, then set can_split=true directly
        // ============================================================
        $venueResponse = $client->request('POST', '/api/venues', [
            'headers' => [
                'X-Club-Id' => $clubId,
                'X-Season-Id' => $seasonId,
            ],
            'json' => [
                'name' => 'Main Gymnasium',
                'source' => 'manual',
                'isActive' => true,
                'isExternal' => false,
            ],
        ]);

        self::assertSame(201, $venueResponse->getStatusCode(), 'Venue creation should return 201');
        $venueData = $venueResponse->toArray();
        self::assertArrayHasKey('id', $venueData);
        $venueId = $venueData['id'];

        // Set canSplit=true directly on entity (API DTO does not yet expose this field)
        $venue = self::$entityManager->getRepository(Venue::class)->find($venueId);
        self::assertInstanceOf(Venue::class, $venue);
        $venue->setCanSplit(true);
        self::$entityManager->flush();
        self::$entityManager->clear();

        // Verify venue persisted with canSplit=true
        $venue = self::$entityManager->getRepository(Venue::class)->find($venueId);
        self::assertTrue($venue->isCanSplit(), 'Venue canSplit must be true');
        self::assertSame('Main Gymnasium', $venue->getName());
        self::assertSame($clubId, $venue->getClubId());
        self::assertSame($seasonId, $venue->getSeasonId());

        // ============================================================
        // STEP 3: Create Team via API with gender, then set new fields directly
        // ============================================================
        $teamResponse = $client->request('POST', '/api/teams', [
            'headers' => [
                'X-Club-Id' => $clubId,
                'X-Season-Id' => $seasonId,
            ],
            'json' => [
                'sportCategoryId' => $sportCategory->getId(),
                'priorityTierId' => $priorityTier->getId(),
                'name' => 'U11 Mixed Team',
                'sessionsPerWeek' => 2,
                'isActive' => true,
                'gender' => 'mixed',
            ],
        ]);

        self::assertSame(201, $teamResponse->getStatusCode(), 'Team creation should return 201');
        $teamData = $teamResponse->toArray();
        self::assertArrayHasKey('id', $teamData);
        $teamId = $teamData['id'];

        // Set new fields directly on entity (API DTO does not yet expose level, isCompetition, size)
        $team = self::$entityManager->getRepository(Team::class)->find($teamId);
        self::assertInstanceOf(Team::class, $team);
        $team->setLevel('U11');
        $team->setIsCompetition(true);
        $team->setSize('small');
        self::$entityManager->flush();
        self::$entityManager->clear();

        // Verify team persisted with all new fields
        $team = self::$entityManager->getRepository(Team::class)->find($teamId);
        self::assertInstanceOf(Team::class, $team);
        self::assertSame('U11', $team->getLevel(), 'Team level must be U11');
        self::assertSame('mixed', $team->getGender(), 'Team gender must be mixed');
        self::assertTrue($team->isIsCompetition(), 'Team isCompetition must be true');
        self::assertSame('small', $team->getSize(), 'Team size must be small');
        self::assertSame('U11 Mixed Team', $team->getName());
        self::assertSame($clubId, $team->getClubId());
        self::assertSame($seasonId, $team->getSeasonId());

        // ============================================================
        // STEP 4: Set preferred slots for team via API
        // ============================================================
        $preferredSlotResponse = $client->request('POST', '/api/team_constraints', [
            'headers' => [
                'X-Club-Id' => $clubId,
                'X-Season-Id' => $seasonId,
            ],
            'json' => [
                'teamId' => $teamId,
                'type' => 'preferred',
                'dayOfWeek' => 3,
                'startTime' => '15:00:00',
                'endTime' => '17:00:00',
                'venueId' => $venueId,
                'reason' => 'Wednesday afternoon preferred',
            ],
        ]);

        self::assertSame(201, $preferredSlotResponse->getStatusCode(), 'Preferred slot creation should return 201');
        $preferredSlotData = $preferredSlotResponse->toArray();
        self::assertArrayHasKey('id', $preferredSlotData);
        $preferredSlotId = $preferredSlotData['id'];

        // Verify preferred slot persisted
        $preferredSlot = self::$entityManager->getRepository(TeamConstraint::class)->find($preferredSlotId);
        self::assertInstanceOf(TeamConstraint::class, $preferredSlot);
        self::assertSame('preferred', $preferredSlot->getType());
        self::assertSame(3, $preferredSlot->getDayOfWeek());
        self::assertSame('15:00:00', $preferredSlot->getStartTime()->format('H:i:s'));
        self::assertSame('17:00:00', $preferredSlot->getEndTime()->format('H:i:s'));
        self::assertSame($venueId, $preferredSlot->getVenueId());
        self::assertSame($teamId, $preferredSlot->getTeamId());
        self::assertSame($clubId, $preferredSlot->getClubId());
        self::assertSame($seasonId, $preferredSlot->getSeasonId());

        // ============================================================
        // STEP 5: Create fixed constraint via API
        // ============================================================
        $fixedConstraintResponse = $client->request('POST', '/api/team_constraints', [
            'headers' => [
                'X-Club-Id' => $clubId,
                'X-Season-Id' => $seasonId,
            ],
            'json' => [
                'teamId' => $teamId,
                'type' => 'required',
                'dayOfWeek' => 1,
                'startTime' => '18:00:00',
                'endTime' => '19:30:00',
                'venueId' => $venueId,
                'reason' => 'Monday evening fixed slot',
            ],
        ]);

        self::assertSame(201, $fixedConstraintResponse->getStatusCode(), 'Fixed constraint creation should return 201');
        $fixedConstraintData = $fixedConstraintResponse->toArray();
        self::assertArrayHasKey('id', $fixedConstraintData);
        $fixedConstraintId = $fixedConstraintData['id'];

        // Verify fixed constraint persisted
        $fixedConstraint = self::$entityManager->getRepository(TeamConstraint::class)->find($fixedConstraintId);
        self::assertInstanceOf(TeamConstraint::class, $fixedConstraint);
        self::assertSame('required', $fixedConstraint->getType());
        self::assertSame(1, $fixedConstraint->getDayOfWeek());
        self::assertSame('18:00:00', $fixedConstraint->getStartTime()->format('H:i:s'));
        self::assertSame('19:30:00', $fixedConstraint->getEndTime()->format('H:i:s'));
        self::assertSame($clubId, $fixedConstraint->getClubId());

        // ============================================================
        // STEP 6: Create forbidden constraint via API
        // ============================================================
        $forbiddenConstraintResponse = $client->request('POST', '/api/team_constraints', [
            'headers' => [
                'X-Club-Id' => $clubId,
                'X-Season-Id' => $seasonId,
            ],
            'json' => [
                'teamId' => $teamId,
                'type' => 'forbidden',
                'dayOfWeek' => 6,
                'startTime' => '08:00:00',
                'endTime' => '22:00:00',
                'reason' => 'No sessions on Saturday',
            ],
        ]);

        self::assertSame(201, $forbiddenConstraintResponse->getStatusCode(), 'Forbidden constraint creation should return 201');
        $forbiddenConstraintData = $forbiddenConstraintResponse->toArray();
        self::assertArrayHasKey('id', $forbiddenConstraintData);
        $forbiddenConstraintId = $forbiddenConstraintData['id'];

        // Verify forbidden constraint persisted
        $forbiddenConstraint = self::$entityManager->getRepository(TeamConstraint::class)->find($forbiddenConstraintId);
        self::assertInstanceOf(TeamConstraint::class, $forbiddenConstraint);
        self::assertSame('forbidden', $forbiddenConstraint->getType());
        self::assertSame(6, $forbiddenConstraint->getDayOfWeek());
        self::assertNull($forbiddenConstraint->getVenueId(), 'Forbidden constraint should not have a venue');
        self::assertSame($clubId, $forbiddenConstraint->getClubId());

        // ============================================================
        // STEP 7: Create Schedule via API
        // ============================================================
        $scheduleResponse = $client->request('POST', '/api/schedules', [
            'headers' => [
                'X-Club-Id' => $clubId,
                'X-Season-Id' => $seasonId,
            ],
            'json' => [
                'name' => 'Spring 2026 Schedule',
                'status' => 'draft',
                'solverSeed' => 42,
            ],
        ]);

        self::assertSame(201, $scheduleResponse->getStatusCode(), 'Schedule creation should return 201');
        $scheduleData = $scheduleResponse->toArray();
        self::assertArrayHasKey('id', $scheduleData);
        $scheduleId = $scheduleData['id'];

        // Verify schedule persisted
        $schedule = self::$entityManager->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertSame('Spring 2026 Schedule', $schedule->getName());
        self::assertSame('draft', $schedule->getStatus());
        self::assertSame($clubId, $schedule->getClubId());
        self::assertSame($seasonId, $schedule->getSeasonId());

        // ============================================================
        // STEP 8: Trigger generation via API (mock engine call)
        // ============================================================
        $mockHttpClient = new MockHttpClient([
            new MockResponse(json_encode([
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
            ], JSON_THROW_ON_ERROR)),
        ]);

        // Call the generation endpoint
        $generateResponse = $client->request('POST', sprintf('/api/schedules/%s/generate', $scheduleId));
        self::assertSame(202, $generateResponse->getStatusCode(), 'Generation trigger should return 202');
        $generateData = $generateResponse->toArray();
        self::assertSame('Schedule generation queued.', $generateData['message']);

        // Directly invoke the handler with a mock HTTP client (container service is already initialized)
        $container = self::getContainer();
        $handler = new GenerateScheduleHandler(
            self::$entityManager,
            $container->get(\App\Service\ScheduleConstraintBuilder::class),
            $container->get(\App\Service\ScheduleResultImporter::class),
            $mockHttpClient,
            $container->get(\Symfony\Component\Mercure\HubInterface::class),
            $container->get(\App\Service\ClubGenerationLock::class),
            $container->get(\App\Service\DiagnosticMessageBuilder::class),
        );
        $message = new GenerateScheduleMessage(
            scheduleId: $scheduleId,
            clubId: $clubId,
        );
        $handler($message);

        // ============================================================
        // STEP 9: Verify generation results
        // ============================================================
        self::$entityManager->clear();
        $updatedSchedule = self::$entityManager->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $updatedSchedule);
        self::assertSame('done', $updatedSchedule->getStatus(), 'Schedule status should be done after successful generation');
        self::assertSame(9500, $updatedSchedule->getScore());
        self::assertSame('1.0.0', $updatedSchedule->getSolverVersion());
        self::assertSame(120, $updatedSchedule->getSolverNbVariables());
        self::assertSame(80, $updatedSchedule->getSolverNbConstraints());
        self::assertSame(0, $updatedSchedule->getSolverNbConflicts());
        self::assertSame(1500, $updatedSchedule->getSolverWallTimeMs());

        // Verify slots were imported
        $slots = self::$entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $scheduleId,
        ]);
        self::assertCount(2, $slots, 'Two slots should have been imported');

        // Verify all entities are linked correctly
        $constraints = self::$entityManager->getRepository(TeamConstraint::class)->findBy([
            'teamId' => $teamId,
        ]);
        self::assertCount(3, $constraints, 'Team should have 3 constraints (preferred + fixed + forbidden)');

        // Final verification: all new fields are still correct after full flow
        $finalTeam = self::$entityManager->getRepository(Team::class)->find($teamId);
        self::assertSame('U11', $finalTeam->getLevel());
        self::assertSame('mixed', $finalTeam->getGender());
        self::assertTrue($finalTeam->isIsCompetition());
        self::assertSame('small', $finalTeam->getSize());

        $finalVenue = self::$entityManager->getRepository(Venue::class)->find($venueId);
        self::assertTrue($finalVenue->isCanSplit());
    }

    /** @group phase1 */
    public function testMissingSourceOnVenueReturnsValidationError(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club);

        $response = self::createClient()->request('POST', '/api/venues', [
            'headers' => [
                'X-Club-Id' => $club->getId(),
                'X-Season-Id' => $season->getId(),
            ],
            'json' => [
                'name' => 'Main Gymnasium',
                'isActive' => true,
                'isExternal' => false,
            ],
        ]);

        self::assertContains($response->getStatusCode(), [400, 422], 'Missing source should not return 500');
    }

    /** @group phase1 */
    public function testMissingStatusOnScheduleReturnsValidationError(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club);

        $response = self::createClient()->request('POST', '/api/schedules', [
            'headers' => [
                'X-Club-Id' => $club->getId(),
                'X-Season-Id' => $season->getId(),
            ],
            'json' => [
                'name' => 'Spring 2026 Schedule',
                'solverSeed' => 42,
            ],
        ]);

        self::assertContains($response->getStatusCode(), [400, 422], 'Missing status should not return 500');
    }

    private function createClub(): Club
    {
        $club = new Club();
        $club->setName('Test Wizard Club');
        $club->setSlug('test-wizard-club-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        self::$entityManager->persist($club);
        self::$entityManager->flush();

        return $club;
    }

    private function createSeason(Club $club): Season
    {
        $season = new Season();
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);

        self::$entityManager->persist($season);
        self::$entityManager->flush();

        return $season;
    }

    private function createSport(): Sport
    {
        $sport = new Sport();
        $sport->setName('Basketball');
        $sport->setSlug('basketball-'.uniqid());
        $sport->setIsActive(true);

        self::$entityManager->persist($sport);
        self::$entityManager->flush();

        return $sport;
    }

    private function createSportCategory(Sport $sport): SportCategory
    {
        $sportCategory = new SportCategory();
        $sportCategory->setSportId($sport->getId());
        $sportCategory->setName('U11');
        $sportCategory->setIsCustom(false);
        $sportCategory->setSortOrder(1);

        self::$entityManager->persist($sportCategory);
        self::$entityManager->flush();

        return $sportCategory;
    }

    private function createPriorityTier(): PriorityTier
    {
        $priorityTier = new PriorityTier();
        $priorityTier->setId(1);
        $priorityTier->setLabel('S');
        $priorityTier->setName('Senior');
        $priorityTier->setColor('#FF0000');
        $priorityTier->setOrToolsWeight(100);
        $priorityTier->setDefaultMinSessions(2);

        self::$entityManager->persist($priorityTier);
        self::$entityManager->flush();

        return $priorityTier;
    }
}
