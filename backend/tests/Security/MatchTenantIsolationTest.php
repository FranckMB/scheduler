<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tenant/season isolation NR for the new match entities (spec gestion-matchs,
 * §7.1 tenant axis): Competition/Fixture of club/season A never leak to club B,
 * writes stamp the resolved club+season, and archived-season writes are
 * refused (409, inherited SeasonAccessGuard).
 */
#[Group('phase1')]
#[Group('integration')]
final class MatchTenantIsolationTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testFixturesAreScopedToTheCallersClub(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        $this->createFixture($clubA, 'Adversaire A');
        [$clubB] = $this->createClubUser('b');
        $this->createFixture($clubB, 'Adversaire B');

        $this->client->request('GET', '/api/fixtures', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        $labels = array_map(
            static fn (array $m): string => $m['opponentLabel'],
            $this->responseData()['member'] ?? [],
        );
        self::assertSame(['Adversaire A'], $labels);
    }

    public function testItemOfAnotherClubIs404(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        [$clubB] = $this->createClubUser('b');
        $foreign = $this->createFixture($clubB, 'Adversaire B');
        $this->em->clear();

        $this->client->request('GET', '/api/fixtures/' . $foreign->getId(), [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPostStampsTheResolvedClubAndSeason(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $teamId = '11111111-1111-4111-8111-111111111111';

        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders($userA) + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'teamId' => $teamId,
            'matchDate' => '2026-10-04',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Nouvel adversaire',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $fixture = $this->em->getRepository(Fixture::class)->findOneBy(['opponentLabel' => 'Nouvel adversaire']);
        self::assertNotNull($fixture);
        self::assertSame($clubA->getId(), $fixture->getClubId());
        self::assertSame($seasonA->getId(), $fixture->getSeasonId());
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus());
    }

    public function testCompetitionCollectionIsScoped(): void
    {
        [$clubA, $userA, $seasonA] = $this->createClubUser('a');
        $this->createCompetition($clubA, $seasonA, 'Championnat A');
        [$clubB, , $seasonB] = $this->createClubUser('b');
        $this->createCompetition($clubB, $seasonB, 'Championnat B');

        $this->client->request('GET', '/api/competitions', [], [], $this->authHeaders($userA));
        self::assertResponseStatusCodeSame(200);
        $names = array_map(static fn (array $m): string => $m['name'], $this->responseData()['member'] ?? []);
        self::assertSame(['Championnat A'], $names);
    }

    public function testWriteOnArchivedSeasonIsRefused(): void
    {
        [$clubA, $userA] = $this->createClubUser('a');
        // Add a PAST season → it becomes archived (read-only).
        $this->scopeGucToClub($clubA->getId());
        $past = $this->season($clubA, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();

        $this->client->request('POST', '/api/fixtures', [], [], $this->authHeaders($userA) + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'matchDate' => '2025-10-04',
            'homeAway' => 'HOME',
            'opponentLabel' => 'Archive',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function createClubUser(string $suffix): array
    {
        $uid = uniqid($suffix, true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club match ' . $suffix);
        $club->setSlug('club-match-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('match' . $uid . '@test.com');
        $user->setFirstName('Match');
        $user->setLastName('User');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        return [$club, $user, $season];
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        // Matches require a validated socle (cockpit state 3) — stamp it so these
        // isolation tests exercise the tenant boundary, not the socle guard.
        $season->setSocleValidatedAt(new DateTimeImmutable);
        $this->em->persist($season);

        return $season;
    }

    private function createFixture(Club $club, string $opponent): Fixture
    {
        $this->scopeGucToClub($club->getId());
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-04'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel($opponent);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createCompetition(Club $club, Season $season, string $name): Competition
    {
        $this->scopeGucToClub($club->getId());
        $competition = new Competition;
        $competition->setClubId($club->getId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId('11111111-1111-4111-8111-111111111111');
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
