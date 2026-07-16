<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Conflict detection: sessions of the baseline plan that fall on a closed venue
 * during a period window (palier A — surfaced, not moved).
 */
#[Group('phase1')]
#[Group('integration')]
final class CalendarEntryConflictsTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const VENUE_X = '11111111-1111-4111-8111-111111111111';
    private const VENUE_Y = '22222222-2222-4222-8222-222222222222';
    private const TEAM_MON = '33333333-3333-4333-8333-333333333331';
    private const TEAM_TUE = '33333333-3333-4333-8333-333333333332';
    private const TEAM_WED = '33333333-3333-4333-8333-333333333333';
    private const TEAM_Y = '33333333-3333-4333-8333-333333333334';

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testClosureSurfacesBaselineSlotsOnClosedVenue(): void
    {
        [$user, $club, $season] = $this->seed('CF1');
        $schedule = $this->baseline($club, $season);
        // Baseline sessions: Monday (N=1) & Tuesday (N=2) on the closed venue X, Wednesday on Y.
        $this->slot($club, $season, $schedule, self::VENUE_X, 1, self::TEAM_MON);
        $this->slot($club, $season, $schedule, self::VENUE_X, 2, self::TEAM_TUE);
        $this->slot($club, $season, $schedule, self::VENUE_Y, 1, self::TEAM_Y);
        $this->em->flush();

        // Period: week of Mon 2026-05-04 → Sun 2026-05-10, venue X closed.
        $entry = $this->closure($club, $season, self::VENUE_X, '2026-05-04', '2026-05-10');

        $this->client->request('GET', "/api/calendar-entries/{$entry->getId()}/conflicts", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([self::VENUE_X], $data['venueIds']);
        $venues = array_column($data['conflicts'], 'venueId');
        self::assertNotContains(self::VENUE_Y, $venues, 'a session on another venue must not conflict');
        self::assertCount(2, $data['conflicts']);

        $byDow = [];
        foreach ($data['conflicts'] as $c) {
            $byDow[$c['dayOfWeek']] = $c['dates'];
        }
        self::assertSame(['2026-05-04'], $byDow[1]); // Monday
        self::assertSame(['2026-05-05'], $byDow[2]); // Tuesday
    }

    public function testShortWindowLimitsConflictingDays(): void
    {
        [$user, $club, $season] = $this->seed('CF2');
        $schedule = $this->baseline($club, $season);
        $this->slot($club, $season, $schedule, self::VENUE_X, 1, self::TEAM_MON);
        $this->slot($club, $season, $schedule, self::VENUE_X, 3, self::TEAM_WED);
        $this->em->flush();

        // Two-day window Mon-Tue only → the Wednesday session does not conflict.
        $entry = $this->closure($club, $season, self::VENUE_X, '2026-05-04', '2026-05-05');

        $this->client->request('GET', "/api/calendar-entries/{$entry->getId()}/conflicts", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $data['conflicts']);
        self::assertSame(1, $data['conflicts'][0]['dayOfWeek']);
    }

    public function testIgnoredEntryRaisesNoConflicts(): void
    {
        [$user, $club, $season] = $this->seed('CF5');
        $schedule = $this->baseline($club, $season);
        $this->slot($club, $season, $schedule, self::VENUE_X, 1, self::TEAM_MON);
        $this->em->flush();

        // Same setup as a conflicting closure — but the manager dismissed it.
        $entry = $this->closure($club, $season, self::VENUE_X, '2026-05-04', '2026-05-10');
        $entry->setStatus(\App\Enum\CalendarEntryStatus::IGNORED);
        $this->em->flush();

        $this->client->request('GET', "/api/calendar-entries/{$entry->getId()}/conflicts", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([], $data['venueIds'], 'an ignored entry must not raise conflicts');
        self::assertSame([], $data['conflicts']);
    }

    public function testForeignEntryIsForbidden(): void
    {
        [$userA, $clubA] = $this->seed('CF3');
        [, $clubB, $seasonB] = $this->seed('CF4');
        $entryB = $this->closure($clubB, $seasonB, self::VENUE_X, '2026-05-04', '2026-05-10');

        $this->client->request('GET', "/api/calendar-entries/{$entryB->getId()}/conflicts", [], [], $this->authHeaders($userA, $clubA));
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(User $user, Club $club): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ];
    }

    private function baseline(Club $club, Season $season): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Baseline');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        // The conflict radar reads the season's calendar = the version the plan
        // points at. Without the pointer it has nothing to compare periods against.
        $this->choosePlanVersion($schedule);

        return $schedule;
    }

    private function slot(Club $club, Season $season, Schedule $schedule, string $venueId, int $dayOfWeek, string $teamId): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setScheduleId($schedule->getId());
        $slot->setTeamId($teamId);
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
    }

    private function closure(Club $club, Season $season, string $venueId, string $start, string $end): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);

        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setName('Venue closed');
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId($venueId);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setCalendarEntryId($entry->getId());
        $this->em->persist($constraint);

        $this->em->flush();

        return $entry;
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
        $user->setFirstName('C');
        $user->setLastName('F');
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
}
