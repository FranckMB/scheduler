<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\TeamConstraint;
use App\Enum\LockLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Integration test for the manual edit drag-edit-persist-verify flow.
 *
 * Covers:
 * 1. Permanent constraint creation
 * 2. Lock update (SOFT / HARD)
 * 3. One-time slot update
 * 4. Conflict validation (coach double-book, venue double-book)
 * 5. Silent SOFT lock scenario (≤30 min time shift)
 */
final class ManualEditFlowTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    /** @var list<string> */
    private array $createdSlotIds = [];

    /** @var list<string> */
    private array $createdScheduleIds = [];

    /** @var list<string> */
    private array $createdSeasonIds = [];

    /** @var list<string> */
    private array $createdClubIds = [];

    /** @var list<string> */
    private array $createdConstraintIds = [];

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->em) {
            foreach ($this->createdConstraintIds as $id) {
                $constraint = $this->em->getRepository(TeamConstraint::class)->find($id);
                if (null !== $constraint) {
                    $this->em->remove($constraint);
                }
            }

            foreach ($this->createdSlotIds as $id) {
                $slot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($id);
                if (null !== $slot) {
                    $this->em->remove($slot);
                }
            }

            foreach ($this->createdScheduleIds as $id) {
                $schedule = $this->em->getRepository(Schedule::class)->find($id);
                if (null !== $schedule) {
                    $this->em->remove($schedule);
                }
            }

            foreach ($this->createdSeasonIds as $id) {
                $season = $this->em->getRepository(Season::class)->find($id);
                if (null !== $season) {
                    $this->em->remove($season);
                }
            }

            foreach ($this->createdClubIds as $id) {
                $club = $this->em->getRepository(Club::class)->find($id);
                if (null !== $club) {
                    $this->em->remove($club);
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        parent::tearDown();
    }

    private function createClub(): Club
    {
        $club = new Club();
        $club->setName('Test Club');
        $club->setSlug('test-club-'.uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->createdClubIds[] = $club->getId();

        return $club;
    }

    private function createSeason(string $clubId): Season
    {
        $season = new Season();
        $season->setClubId($clubId);
        $season->setName('2025-2026');
        $season->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->createdSeasonIds[] = $season->getId();

        return $season;
    }

    private function createSchedule(string $clubId, string $seasonId): Schedule
    {
        $schedule = new Schedule();
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($seasonId);
        $schedule->setName('Test Schedule');
        $schedule->setStatus('draft');
        $schedule->setSnapshotData([]);
        $this->em->persist($schedule);
        $this->createdScheduleIds[] = $schedule->getId();

        return $schedule;
    }

    private function createSlot(
        string $clubId,
        string $seasonId,
        string $scheduleId,
        string $teamId,
        string $venueId,
        ?string $coachId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
    ): ScheduleSlotTemplate {
        $slot = new ScheduleSlotTemplate();
        $slot->setClubId($clubId);
        $slot->setSeasonId($seasonId);
        $slot->setScheduleId($scheduleId);
        $slot->setTeamId($teamId);
        $slot->setVenueId($venueId);
        $slot->setCoachId($coachId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new \DateTimeImmutable($startTime));
        $slot->setDurationMinutes($durationMinutes);
        $slot->setLockLevel(LockLevel::NONE);
        $this->em->persist($slot);
        $this->createdSlotIds[] = $slot->getId();

        return $slot;
    }

    /** @group phase1 */
    public function testPermanentConstraintCreatesTeamConstraint(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/constraint',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'type' => 'forbidden',
                'reason' => 'No evening games',
                'createdBy' => '77777777-7777-7777-7777-777777777777',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(201, $response->getStatusCode(), 'Permanent constraint should return 201');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('constraintId', $data, 'Response should contain constraintId');
        self::assertNotEmpty($data['constraintId'], 'constraintId should not be empty');
        $this->createdConstraintIds[] = $data['constraintId'];

        $constraint = $this->em->getRepository(TeamConstraint::class)->find($data['constraintId']);
        self::assertNotNull($constraint, 'TeamConstraint should be persisted');
        self::assertSame('forbidden', $constraint->getType());
        self::assertSame($slot->getClubId(), $constraint->getClubId());
        self::assertSame($slot->getSeasonId(), $constraint->getSeasonId());
        self::assertSame($slot->getTeamId(), $constraint->getTeamId());
        self::assertSame($slot->getDayOfWeek(), $constraint->getDayOfWeek());
        self::assertSame('18:00', $constraint->getStartTime()?->format('H:i'));
        self::assertSame('19:30', $constraint->getEndTime()?->format('H:i'));
        self::assertSame($slot->getVenueId(), $constraint->getVenueId());
        self::assertSame('No evening games', $constraint->getReason());
        self::assertSame('77777777-7777-7777-7777-777777777777', $constraint->getCreatedBy());
        self::assertSame('manual_edit', $constraint->getSource());
    }

    /** @group phase1 */
    public function testApplySoftLockUpdatesSlot(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/lock',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lockLevel' => 'SOFT',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), 'Lock application should return 200');

        $this->em->clear();
        $updatedSlot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertNotNull($updatedSlot);
        self::assertSame(LockLevel::SOFT, $updatedSlot->getLockLevel());
    }

    /** @group phase1 */
    public function testApplyHardLockUpdatesSlot(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/lock',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lockLevel' => 'HARD',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), 'Lock application should return 200');

        $this->em->clear();
        $updatedSlot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertNotNull($updatedSlot);
        self::assertSame(LockLevel::HARD, $updatedSlot->getLockLevel());
    }

    /** @group phase1 */
    public function testOneTimeUpdateModifiesSlot(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/one-time',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'dayOfWeek' => 3,
                'startTime' => '14:00',
                'durationMinutes' => 120,
                'venueId' => '55555555-5555-5555-5555-555555555555',
                'coachId' => '66666666-6666-6666-6666-666666666666',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), 'One-time update should return 200');

        $this->em->clear();
        $updatedSlot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertNotNull($updatedSlot);
        self::assertSame(3, $updatedSlot->getDayOfWeek());
        self::assertSame('14:00', $updatedSlot->getStartTime()->format('H:i'));
        self::assertSame(120, $updatedSlot->getDurationMinutes());
        self::assertSame('55555555-5555-5555-5555-555555555555', $updatedSlot->getVenueId());
        self::assertSame('66666666-6666-6666-6666-666666666666', $updatedSlot->getCoachId());
    }

    /** @group phase1 */
    public function testOneTimeUpdateReturns409OnCoachDoubleBook(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $conflictingSlot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '44444444-4444-4444-4444-444444444444',
            '55555555-5555-5555-5555-555555555555',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:30',
            60,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/one-time',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'coachId' => '33333333-3333-3333-3333-333333333333',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(409, $response->getStatusCode(), 'Coach double-book should return 409');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data, 'Error response should contain error message');
        self::assertStringContainsString('Conflict detected', $data['error']);
    }

    /** @group phase1 */
    public function testOneTimeUpdateReturns409OnVenueDoubleBook(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $conflictingSlot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '44444444-4444-4444-4444-444444444444',
            '22222222-2222-2222-2222-222222222222',
            '66666666-6666-6666-6666-666666666666',
            1,
            '18:30',
            60,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/one-time',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'venueId' => '22222222-2222-2222-2222-222222222222',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(409, $response->getStatusCode(), 'Venue double-book should return 409');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data, 'Error response should contain error message');
        self::assertStringContainsString('Conflict detected', $data['error']);
    }

    /** @group phase1 */
    public function testSilentSoftLockForSmallTimeShift(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        // Shift start time by exactly 30 minutes — should succeed without conflicts
        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/one-time',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'startTime' => '18:30',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), '30-minute shift should return 200');

        $this->em->clear();
        $updatedSlot = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertNotNull($updatedSlot);
        self::assertSame('18:30', $updatedSlot->getStartTime()->format('H:i'));
    }

    /** @group phase1 */
    public function testInvalidLockLevelReturns400(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/lock',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'lockLevel' => 'INVALID',
            ], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(400, $response->getStatusCode(), 'Invalid lock level should return 400');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    /** @group phase1 */
    public function testMissingTypeOnConstraintReturns400(): void
    {
        $club = $this->createClub();
        $season = $this->createSeason($club->getId());
        $schedule = $this->createSchedule($club->getId(), $season->getId());
        $slot = $this->createSlot(
            $club->getId(),
            $season->getId(),
            $schedule->getId(),
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            1,
            '18:00',
            90,
        );
        $this->em->flush();

        $request = Request::create(
            '/api/schedule-slots/'.$slot->getId().'/manual-edit/constraint',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(400, $response->getStatusCode(), 'Missing type should return 400');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    /** @group phase1 */
    public function testSlotNotFoundReturns404(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $request = Request::create(
            '/api/schedule-slots/'.$fakeId.'/manual-edit/constraint',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['type' => 'forbidden'], JSON_THROW_ON_ERROR)
        );

        $response = self::$kernel->handle($request);
        self::assertSame(404, $response->getStatusCode(), 'Missing slot should return 404');
    }
}
