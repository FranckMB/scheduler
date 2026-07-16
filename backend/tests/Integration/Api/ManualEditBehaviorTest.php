<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * BCK-08: behavioural coverage of the 3 manual-edit routes (planning-lifecycle
 * structuring axis). Beyond the SEC-07 role gate (ManagementRoleTest), this
 * proves each route actually DOES its job: creates the permanent constraint,
 * sets the lock, moves the slot, and refuses a move that would collide.
 */
#[Group('phase1')]
#[Group('integration')]
final class ManualEditBehaviorTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testApplyConstraintCreatesAPermanentConstraint(): void
    {
        [$user, , $season] = $this->seed('MED1');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedule-slots/{$slot->getId()}/manual-edit/constraint", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['type' => 'PIN_SLOT', 'reason' => 'Fixé par le coach'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['constraintId']);

        $this->em->clear();
        $this->scopeGucToClub($season->getClubId());
        $constraint = $this->em->getRepository(Constraint::class)->find($data['constraintId']);
        self::assertNotNull($constraint, 'the manual edit must persist a Constraint');
        self::assertSame($slot->getTeamId(), $constraint->getScopeTargetId());
        self::assertSame('manual_edit', $constraint->getSource());
    }

    public function testApplyLockSetsTheSlotLockLevel(): void
    {
        [$user, , $season] = $this->seed('MED2');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedule-slots/{$slot->getId()}/manual-edit/lock", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['lockLevel' => 'HARD'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($season->getClubId());
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame(LockLevel::HARD, $reloaded?->getLockLevel());
    }

    public function testSoftLockIsRejected(): void
    {
        // ENG-21: SOFT lock is a placebo (the engine never honors its penalty), so the
        // endpoint rejects it (400) rather than accept a lock with zero placement effect.
        [$user, , $season] = $this->seed('MEDSOFT');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedule-slots/{$slot->getId()}/manual-edit/lock", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['lockLevel' => 'SOFT'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        $this->scopeGucToClub($season->getClubId());
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertNotSame(LockLevel::SOFT, $reloaded?->getLockLevel(), 'the SOFT lock must not persist');
    }

    public function testOneTimeUpdateMovesTheSlot(): void
    {
        [$user, , $season] = $this->seed('MED3');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedule-slots/{$slot->getId()}/manual-edit/one-time", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startTime' => '20:00'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($season->getClubId());
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($slot->getId());
        self::assertSame('20:00', $reloaded?->getStartTime()->format('H:i'));
    }

    public function testOneTimeUpdateRefusesAConflictingMove(): void
    {
        [$user, , $season] = $this->seed('MED4');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        // Two slots, same venue, same day; moving A onto B's time overlaps.
        $slotA = $this->createSlot($schedule, dayOfWeek: 3, startHm: '18:00', venueId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->createSlot($schedule, dayOfWeek: 3, startHm: '19:00', venueId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedule-slots/{$slotA->getId()}/manual-edit/one-time", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['startTime' => '19:15'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'a move overlapping another slot in the same venue must 409');
    }

    public function testTheChosenVersionIsReadOnly(): void
    {
        [$user, , $season] = $this->seed('MED5');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($schedule);
        $slot = $this->createSlot($schedule, dayOfWeek: 2, startHm: '18:00');

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedule-slots/{$slot->getId()}/manual-edit/lock", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['lockLevel' => 'HARD'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409, 'a validated (read-only) schedule refuses manual edits');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('M');
        $user->setLastName('ED');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    private function createSchedule(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function createSlot(
        Schedule $schedule,
        int $dayOfWeek,
        string $startHm,
        string $venueId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    ): ScheduleSlotTemplate {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($schedule->getClubId());
        $slot->setSeasonId($schedule->getSeasonId());
        $slot->setScheduleId($schedule->getId());
        $slot->setTeamId('44444444-4444-4444-8444-444444444444');
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        // Epoch-reset (!H:i) to match how ManualEditController parses an incoming
        // startTime, so the conflict comparison operates on the same date basis.
        $slot->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $startHm));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }
}
