<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Message\ExportPdfMessage;
use App\Message\GenerateScheduleMessage;
use App\MessageHandler\ExportPdfHandler;
use App\MessageHandler\GenerateScheduleHandler;
use App\Service\PdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Integration test for the full registration → PDF export flow.
 *
 * Covers:
 * 1. User registration via AuthController
 * 2. Club/season creation (auto-created by registration)
 * 3. Venue and team creation
 * 4. Schedule creation
 * 5. Schedule generation trigger (mocked engine HTTP call)
 * 6. PDF export trigger (mocked PDF generator)
 * 7. Status progression verification
 */
final class ExportPdfFlowTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?MockResponse $engineResponse = null;

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        // Set mock HTTP client immediately after boot, before any requests,
        // so it is not already initialized when we need to replace it.
        $mockHttpClient = new MockHttpClient(
            function (string $method, string $url, array $options): MockResponse {
                if (null !== $this->engineResponse) {
                    return $this->engineResponse;
                }

                return new MockResponse('Not configured', ['http_code' => 500]);
            }
        );
        self::getContainer()->set(HttpClientInterface::class, $mockHttpClient);
    }

    protected function tearDown(): void
    {
        if (null !== $this->em) {
            $this->em->getConnection()->rollBack();
            $this->em->close();
            $this->em = null;
        }

        $this->engineResponse = null;
        parent::tearDown();
    }

    private function generateUniqueAra(): string
    {
        return 'ARA'.strtoupper(substr(md5(uniqid()), 0, 10));
    }

    /** @group phase1 */
    public function testFullRegistrationToPdfExportFlow(): void
    {
        $container = self::getContainer();

        // Mock Mercure hub to avoid external dependency
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('update-id');
        $container->set(HubInterface::class, $hub);

        // Mock PDF generator to avoid real filesystem usage
        $pdfGenerator = $this->createMock(PdfGenerator::class);
        $pdfGenerator->method('generate')->willReturn('/exports/schedule-test.pdf');
        $container->set(PdfGenerator::class, $pdfGenerator);

        // ============================================================
        // STEP 1: Register user via AuthController
        // ============================================================
        $unique = uniqid();
        $ara = $this->generateUniqueAra();

        $registerRequest = Request::create(
            '/api/register',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'test'.$unique.'@example.com',
                'password' => 'SecurePass123!',
                'ara' => $ara,
                'club_name' => 'Test Club '.$unique,
            ], JSON_THROW_ON_ERROR)
        );

        $registerResponse = self::$kernel->handle($registerRequest);
        self::assertSame(201, $registerResponse->getStatusCode(), 'Registration should return 201');

        $registerData = json_decode((string) $registerResponse->getContent(), true);
        self::assertArrayHasKey('token', $registerData, 'Response should contain JWT token');
        self::assertNotEmpty($registerData['token'], 'JWT token should not be empty');
        self::assertArrayHasKey('user', $registerData, 'Response should contain user data');
        self::assertArrayHasKey('id', $registerData['user'], 'User data should contain id');

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertNotNull($club, 'Club should be created with the given ARA');
        self::assertFalse($club->getOnboardingCompleted(), 'Club onboarding should not be completed');
        $clubId = $club->getId();

        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season, 'Default season should be created');
        $seasonId = $season->getId();

        $sportCategories = $this->em->getRepository(SportCategory::class)->findBy(['clubId' => $clubId]);
        self::assertCount(9, $sportCategories, 'Default sport categories should be created');
        $categoryNames = array_map(fn ($cat) => $cat->getName(), $sportCategories);
        self::assertContains('U7', $categoryNames, 'U7 category should exist');
        self::assertContains('U9', $categoryNames, 'U9 category should exist');
        self::assertContains('U11', $categoryNames, 'U11 category should exist');
        $sportCategory = $sportCategories[0];
        $sportCategoryId = $sportCategory->getId();

        // ============================================================
        // STEP 2: Create venue
        // ============================================================
        $venue = new Venue();
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Main Hall');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $venueId = $venue->getId();

        self::assertNotEmpty($venueId, 'Venue should have an ID');

        // ============================================================
        // STEP 3: Create team
        // ============================================================
        $team = new Team();
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($sportCategoryId);
        $team->setPriorityTierId(1);
        $team->setName('Test Team');
        $team->setSessionsPerWeek(2);
        $team->setGender('mixed');
        $this->em->persist($team);
        $this->em->flush();
        $teamId = $team->getId();

        self::assertNotEmpty($teamId, 'Team should have an ID');

        // ============================================================
        // STEP 4: Create schedule
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
        // STEP 5: Configure engine mock response with valid UUIDs
        // ============================================================
        $this->engineResponse = new MockResponse(json_encode([
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
                'teamId' => '88888888-8888-4888-8888-888888888888',
                'venueId' => '99999999-9999-4999-9999-999999999999',
                'coachId' => '66666666-6666-4666-8666-666666666666',
                'dayOfWeek' => 2,
                'startTime' => '18:00',
                'durationMinutes' => 90,
            ]],
            'unplaced' => [],
            'warnings' => [],
        ], JSON_THROW_ON_ERROR));

        // ============================================================
        // STEP 6: Trigger schedule generation via controller
        // ============================================================
        $generateRequest = Request::create(
            sprintf('/api/schedules/%s/generate', $scheduleId),
            'POST'
        );
        $generateResponse = self::$kernel->handle($generateRequest);
        self::assertSame(202, $generateResponse->getStatusCode(), 'Generation trigger should return 202');

        $generateData = json_decode((string) $generateResponse->getContent(), true);
        self::assertSame('Schedule generation queued', $generateData['message']);

        // Manually invoke handler (async bus not available in test without worker)
        $handler = $container->get(GenerateScheduleHandler::class);
        $message = new GenerateScheduleMessage($scheduleId, $clubId);
        $handler($message);

        // Verify schedule status progression: draft → generating → done
        $this->em->clear();
        $scheduleAfterGeneration = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertNotNull($scheduleAfterGeneration);
        self::assertSame('done', $scheduleAfterGeneration->getStatus(), 'Schedule status should be done after generation');
        self::assertSame(94, $scheduleAfterGeneration->getScore());
        self::assertSame('engine-1', $scheduleAfterGeneration->getSolverVersion());
        self::assertSame(10, $scheduleAfterGeneration->getSolverNbVariables());
        self::assertSame(20, $scheduleAfterGeneration->getSolverNbConstraints());
        self::assertSame(300, $scheduleAfterGeneration->getSolverWallTimeMs());

        // Verify slots were imported
        $slots = $this->em->getRepository(\App\Entity\ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $scheduleId,
        ]);
        self::assertCount(1, $slots, 'One slot should have been imported');

        // ============================================================
        // STEP 7: Trigger PDF export via controller
        // ============================================================
        $exportRequest = Request::create(
            sprintf('/api/schedules/%s/export-pdf', $scheduleId),
            'POST'
        );
        $exportResponse = self::$kernel->handle($exportRequest);
        self::assertSame(202, $exportResponse->getStatusCode(), 'PDF export trigger should return 202');

        $exportData = json_decode((string) $exportResponse->getContent(), true);
        self::assertSame('PDF export queued.', $exportData['message']);

        // Verify PDF status is pending immediately after controller
        $this->em->clear();
        $scheduleAfterExportTrigger = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertNotNull($scheduleAfterExportTrigger);
        self::assertSame('pending', $scheduleAfterExportTrigger->getPdfExportStatus(), 'PDF export status should be pending after controller');

        // Manually invoke PDF handler
        $pdfHandler = $container->get(ExportPdfHandler::class);
        $pdfMessage = new ExportPdfMessage($scheduleId);
        $pdfHandler($pdfMessage);

        // ============================================================
        // STEP 8: Verify final PDF status progression: pending → generating → completed
        // ============================================================
        $this->em->clear();
        $finalSchedule = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertNotNull($finalSchedule);
        self::assertSame('completed', $finalSchedule->getPdfExportStatus(), 'PDF export status should be completed after handler');
        self::assertSame('/exports/schedule-test.pdf', $finalSchedule->getPdfExportUrl());
    }
}
