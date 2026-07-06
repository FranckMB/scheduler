<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/seasons/{id}/transition — auth/role/precondition surface.
 * The copy semantics themselves are covered by SeasonTransitionServiceTest.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonTransitionApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('POST', '/api/seasons/00000000-0000-4000-8000-000000000000/transition');
        self::assertResponseStatusCodeSame(401);
    }

    public function testNonManagementMemberIsForbidden(): void
    {
        [$user, , $season] = $this->createClubUserSeason('coach');

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(403);
    }

    public function testNominalTransitionCreatesTheDraftSeason(): void
    {
        [$user, , $season] = $this->createClubUserSeason('admin');

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        self::assertNotSame($season->getId(), $data['seasonId']);
        self::assertSame($season->getStartDate()->modify('+1 year')->format('Y-m-d'), $data['startDate']);
        self::assertIsArray($data['counts']);
    }

    public function testRerunConflictsWithTheExistingSuccessorId(): void
    {
        [$user, , $season] = $this->createClubUserSeason('admin');
        $auth = $this->authHeaders($user);

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(201);
        $createdId = $this->responseData()['seasonId'];

        $this->client->request('POST', '/api/seasons/' . $season->getId() . '/transition', [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        self::assertSame($createdId, $this->responseData()['existingSeasonId']);
    }

    public function testPastSeasonSourceIsRefused(): void
    {
        [$user, $club] = $this->createClubUserSeason('admin');
        $this->scopeGucToClub($club->getId());
        $past = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1);
        $this->em->flush();

        $this->client->request('POST', '/api/seasons/' . $past->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignSeasonIsNotFound(): void
    {
        [$user] = $this->createClubUserSeason('admin');
        [, , $foreignSeason] = $this->createClubUserSeason('admin');

        $this->client->request('POST', '/api/seasons/' . $foreignSeason->getId() . '/transition', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubUserSeason(string $role): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club api-transition');
        $club->setSlug('club-api-transition-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TRB' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('transition' . $uid . '@test.com');
        $user->setFirstName('Trans');
        $user->setLastName('Ition');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = $this->season($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        return [$user, $club, $season];
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
        $this->em->persist($season);

        return $season;
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
