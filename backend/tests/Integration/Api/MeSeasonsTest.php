<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /api/me multi-season contract (transition-de-saison P1): exposes the club's
 * seasons with current/readonly flags, and the cockpit-gate fields
 * (baselineScheduleId / socleValidatedAt) follow the SELECTED season.
 */
#[Group('phase1')]
#[Group('integration')]
final class MeSeasonsTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testMeExposesSeasonsWithCurrentAndReadonlyFlags(): void
    {
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [$past, $current, $draft] = $seasons;

        $this->client->request('GET', '/api/me', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();

        self::assertSame($current->getId(), $data['currentSeasonId']);
        self::assertCount(3, $data['seasons']);
        // Ordered by startDate ASC.
        self::assertSame([$past->getId(), $current->getId(), $draft->getId()], array_column($data['seasons'], 'id'));
        self::assertSame([false, true, false], array_column($data['seasons'], 'isCurrent'));
        self::assertSame([true, false, false], array_column($data['seasons'], 'isReadonly'));
    }

    public function testTheSeasonPlanFollowsTheSelectedSeason(): void
    {
        // /api/me.seasonPlan is the frontend's ONLY seam onto "is this season
        // settled?" — it must describe the SELECTED season, not the current one,
        // or switching seasons would strand the cockpit on the wrong plan.
        [$user, , $seasons] = $this->createClubWithThreeSeasons();
        [, $current, $draft] = $seasons;

        $chosen = $this->settleSeasonPlan($current);
        $auth = $this->authHeaders($user);

        // No header → the current season's plan.
        $this->client->request('GET', '/api/me', [], [], $auth);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame($chosen->getId(), $data['seasonPlan']['chosenScheduleId']);
        self::assertTrue($data['seasonPlan']['hasFinishedVersion']);

        // Draft season selected → its own plan: an empty espace de travail, so
        // the cockpit falls back to the guided mode for the brand-new season.
        $this->client->request('GET', '/api/me', [], [], $auth + [
            'HTTP_X-Season-Id' => $draft->getId(),
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertNull($data['seasonPlan']['chosenScheduleId']);
        self::assertFalse($data['seasonPlan']['hasFinishedVersion']);
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
        $club->setName('Club me-seasons');
        $club->setSlug('club-me-seasons-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('MES' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('me-seasons' . $uid . '@test.com');
        $user->setFirstName('Me');
        $user->setLastName('Seasons');
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

        // A real season owns its (empty) SEASON plan from birth — persisting the
        // rows by hand skips the provisioning every creation path does.
        foreach ([$past, $current, $draft] as $season) {
            $this->provisionSeasonPlan($season);
        }

        return [$user, $club, [$past, $current, $draft]];
    }

    private function createSeason(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($startYear . '-' . ($startYear + 1));
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    /**
     * Stateless JWT firewall: a Bearer token must ride EVERY request.
     *
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
