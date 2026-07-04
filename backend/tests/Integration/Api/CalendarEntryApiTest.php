<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CalendarEntry CRUD, validation shape, window filtering, and tenant isolation
 * (NR — a club must never see another club's calendar entries).
 */
#[Group('phase1')]
#[Group('integration')]
final class CalendarEntryApiTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testCreateEvent(): void
    {
        [$user, $club] = $this->seed('CE1');

        $this->post($user, $club, [
            'kind' => 'event',
            'title' => 'AG du club',
            'startDate' => '2026-05-12',
            'endDate' => '2026-05-12',
            'isDisruptive' => false,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('event', $data['kind']);
        self::assertSame('2026-05-12', $data['startDate']);
        self::assertSame('active', $data['status']);
    }

    public function testCreatePeriodClosure(): void
    {
        [$user, $club] = $this->seed('CE2');

        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Gym Barros fermé',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
            'periodType' => 'closure',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('closure', $data['periodType']);
    }

    public function testEndDateBeforeStartDateIsRejected(): void
    {
        [$user, $club] = $this->seed('CE3');

        $this->post($user, $club, [
            'kind' => 'event',
            'title' => 'Bad',
            'startDate' => '2026-05-12',
            'endDate' => '2026-05-01',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEventWithPeriodTypeIsRejected(): void
    {
        [$user, $club] = $this->seed('CE4');

        $this->post($user, $club, [
            'kind' => 'event',
            'title' => 'Bad',
            'startDate' => '2026-05-12',
            'endDate' => '2026-05-12',
            'periodType' => 'closure',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPeriodWithoutPeriodTypeIsRejected(): void
    {
        [$user, $club] = $this->seed('CE5');

        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Bad',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testWindowFilterReturnsOverlappingEntries(): void
    {
        [$user, $club] = $this->seed('CE6');

        // Entry straddling the May/June boundary → overlaps a May window.
        $this->post($user, $club, ['kind' => 'period', 'title' => 'Straddle', 'startDate' => '2026-05-28', 'endDate' => '2026-06-03', 'periodType' => 'closure']);
        self::assertResponseStatusCodeSame(201);
        // Entry fully in July → outside a May window.
        $this->post($user, $club, ['kind' => 'event', 'title' => 'July', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/calendar_entries?from=2026-05-01&to=2026-05-31', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $titles = array_map(static fn (array $e): string => $e['title'], $data['member']);
        self::assertContains('Straddle', $titles);
        self::assertNotContains('July', $titles);
    }

    public function testKindFilter(): void
    {
        [$user, $club] = $this->seed('CE7');

        $this->post($user, $club, ['kind' => 'event', 'title' => 'Evt', 'startDate' => '2026-05-12', 'endDate' => '2026-05-12']);
        $this->post($user, $club, ['kind' => 'period', 'title' => 'Per', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10', 'periodType' => 'closure']);

        $this->client->request('GET', '/api/calendar_entries?kind=period', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $kinds = array_map(static fn (array $e): string => $e['kind'], $data['member']);
        self::assertNotContains('event', $kinds);
        self::assertContains('period', $kinds);
    }

    public function testForeignEntryIsInvisible(): void
    {
        [$userA, $clubA] = $this->seed('CE8');
        [, $clubB] = $this->seed('CE9');

        // Create an entry in club B (scope the GUC to B first).
        $this->scopeGucToClub($clubB->getId());
        $entryB = new \App\Entity\CalendarEntry;
        $entryB->setClubId($clubB->getId());
        $entryB->setSeasonId($this->activeSeasonId($clubB));
        $entryB->setKind(\App\Enum\CalendarEntryKind::EVENT);
        $entryB->setTitle('Secret B');
        $entryB->setStartDate(new DateTimeImmutable('2026-05-12'));
        $entryB->setEndDate(new DateTimeImmutable('2026-05-12'));
        $this->em->persist($entryB);
        $this->em->flush();

        // Club A must not see it.
        $this->client->request('GET', '/api/calendar_entries', [], [], $this->authHeaders($userA, $clubA));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $titles = array_map(static fn (array $e): string => $e['title'], $data['member']);
        self::assertNotContains('Secret B', $titles);
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
     * The api firewall is stateless JWT — every request carries a Bearer token
     * (loginUser only authenticates a single following request).
     *
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
        $this->client->request('POST', '/api/calendar_entries', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function activeSeasonId(Club $club): string
    {
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId(), 'status' => 'active']);
        self::assertNotNull($season);

        return $season->getId();
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
        $user->setLastName('E');
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
