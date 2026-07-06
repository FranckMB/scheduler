<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Read-only season NR (transition-de-saison §3, planning-lifecycle axis): once
 * a season is archived (N-1 and older), every write targeting it is refused
 * with 409 — both the generic API Platform mutations (SeasonAccessGuard in
 * AbstractStateProcessor) and the custom write controllers
 * (SeasonReadonlyGuardListener). Reads stay open; the current and draft
 * seasons remain writable.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonReadonlyTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testGenericWriteOnAPastSeasonIs409(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $auth = $this->authHeaders($user);

        // POST a venue into the archived season → refused.
        $this->client->request('POST', '/api/venues', [], [], $auth + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Gym archive', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testDeleteOnAPastSeasonIs409(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $venue = $this->createVenue($club, $past, 'Gym N-1');

        $this->client->request('DELETE', '/api/venues/' . $venue->getId(), [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testManagementGatedControllerOnAPastSeasonIs409AfterAuth(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;

        // reset-season gates management-role (403) THEN refuses the archive
        // (409, inline so authorization wins first). Admin user → 409.
        $this->client->request('DELETE', '/api/reset-season', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testListenerGuardedControllerOnAPastSeasonIs409(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;

        // reorder-teams is a SeasonScopedWrite controller: the kernel.controller
        // listener refuses the archive (409) before __invoke even reads the body.
        $this->client->request('POST', '/api/teams/reorder', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['teamIds' => []], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testConstraintValidationStaysReadableOnAPastSeason(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;

        // Pure read (validation report) — NOT a write, must not 409 on an archive.
        $this->client->request('POST', '/api/constraints/validate', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testReadOnAPastSeasonIsAllowed(): void
    {
        [$user, $club, $seasons] = $this->createClubWithThreeSeasons();
        [$past] = $seasons;
        $this->createVenue($club, $past, 'Gym N-1');

        $this->client->request('GET', '/api/venues', [], [], $this->authHeaders($user) + [
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testWriteOnCurrentAndDraftSeasonsIsAllowed(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [, , $draft] = $seasons;
        $auth = $this->authHeaders($user);

        // Current (no header).
        $this->client->request('POST', '/api/venues', [], [], $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'Gym courant', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Draft N+1.
        $this->client->request('POST', '/api/venues', [], [], $auth + [
            'HTTP_X-Season-Id' => $draft->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['name' => 'Gym brouillon', 'source' => 'manual'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: User, 1: Club, 2: array{0: Season, 1: Season, 2: Season}}
     */
    private function createClubWithThreeSeasons(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club readonly');
        $club->setSlug('club-readonly-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('RDO' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('readonly' . $uid . '@test.com');
        $user->setFirstName('Read');
        $user->setLastName('Only');
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

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $past = $this->createSeason($club, $year - 1);
        $current = $this->createSeason($club, $year);
        $draft = $this->createSeason($club, $year + 1);
        $this->em->flush();

        return [$user, $club, [$past, $current, $draft]];
    }

    private function createSeason(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function createVenue(Club $club, Season $season, string $name): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
