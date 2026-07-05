<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creating a period overlay via POST /api/schedules with calendarEntryId: the
 * server stamps the inverse link and guards the target entry (422).
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleOverlayCreationTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testCreateOverlayLinksEntry(): void
    {
        [$user, $club, $season] = $this->seed('OV1');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);

        $this->post($user, $club, ['name' => 'Vacances', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($entry->getId(), $data['calendarEntryId']);

        // Read the entry back through the API (real read path) → inverse link set.
        $this->client->request('GET', "/api/calendar_entries/{$entry->getId()}", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $entryData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($data['id'], $entryData['overlayScheduleId'], 'server must stamp the inverse link');
    }

    public function testHolidayOverlayAllowed(): void
    {
        [$user, $club, $season] = $this->seed('OV2');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::HOLIDAY);

        $this->post($user, $club, ['name' => 'Toussaint', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testSecondOverlayRejected(): void
    {
        [$user, $club, $season] = $this->seed('OV3');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $entry->setOverlayScheduleId('11111111-1111-4111-8111-111111111111');
        $this->em->flush();

        $this->post($user, $club, ['name' => 'Dup', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEventEntryRejected(): void
    {
        [$user, $club, $season] = $this->seed('OV4');
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::EVENT);
        $entry->setTitle('AG');
        $entry->setStartDate(new DateTimeImmutable('2026-05-12'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-12'));
        $this->em->persist($entry);
        $this->em->flush();

        $this->post($user, $club, ['name' => 'X', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCutoffPeriodRejected(): void
    {
        [$user, $club, $season] = $this->seed('OV5');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CUTOFF);

        $this->post($user, $club, ['name' => 'X', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testForeignEntryRejected(): void
    {
        [$userA, $clubA] = $this->seed('OV6');
        [, $clubB, $seasonB] = $this->seed('OV7');
        $entryB = $this->period($clubB, $seasonB, CalendarEntryPeriodType::CLOSURE);

        // Club A tries to overlay club B's entry → invisible under RLS → 422.
        $this->post($userA, $clubA, ['name' => 'X', 'status' => 'DRAFT', 'calendarEntryId' => $entryB->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOverlayRejectedWithoutBaseline(): void
    {
        [$user, $club, $season] = $this->seed('OV9');
        // Remove the seeded baseline → no socle yet.
        $season->setBaselineScheduleId(null);
        $this->em->flush();
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);

        $this->post($user, $club, ['name' => 'X', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCalendarEntryIdImmutableOnPut(): void
    {
        [$user, $club, $season] = $this->seed('OV8');
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $this->post($user, $club, ['name' => 'O', 'status' => 'DRAFT', 'calendarEntryId' => $entry->getId()]);
        $scheduleId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // PUT trying to detach the overlay marker must not change it.
        $this->client->request('PUT', "/api/schedules/{$scheduleId}", [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => 'Renamed', 'status' => 'DRAFT', 'calendarEntryId' => null], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        // GET back through the API: the overlay marker is unchanged.
        $this->client->request('GET', "/api/schedules/{$scheduleId}", [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $reloaded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($entry->getId(), $reloaded['calendarEntryId'], 'overlay marker is immutable on PUT');
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

    /**
     * @param array<string, mixed> $payload
     */
    private function post(User $user, Club $club, array $payload): void
    {
        $this->client->request('POST', '/api/schedules', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function period(Club $club, Season $season, CalendarEntryPeriodType $type): CalendarEntry
    {
        $this->scopeGucToClub($club->getId());
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType($type);
        $entry->setTitle('Période ' . $type->value);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
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
        $user->setFirstName('O');
        $user->setLastName('V');
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

        // An overlay is only allowed once the season has a validated baseline.
        $baseline = new Schedule;
        $baseline->setClubId($club->getId());
        $baseline->setSeasonId($season->getId());
        $baseline->setName('Baseline');
        $baseline->setStatus(ScheduleStatus::VALIDATED);
        $this->em->persist($baseline);
        $this->em->flush();
        $season->setBaselineScheduleId($baseline->getId());

        $this->em->flush();

        return [$user, $club, $season];
    }
}
