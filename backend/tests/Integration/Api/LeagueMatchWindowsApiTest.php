<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\LeagueMatchWindow;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamLevel;
use App\Service\LeagueResolver;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The league-match-window catalog is a GLOBAL reference (spec §6bis): shared by
 * every club, carries no club data, and a club with no catalogued league falls
 * back to the AURA federation default.
 */
#[Group('phase1')]
#[Group('integration')]
final class LeagueMatchWindowsApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testAuraClubGetsTheAuraEnvelope(): void
    {
        $user = $this->clubUser('AURA'); // ARA prefix → AURA league

        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('AURA', $data['league']);
        self::assertSame(['AURA'], array_values(array_unique(array_column($data['items'], 'league'))));
    }

    public function testUncataloguedLeagueFallsBackToAuraDefault(): void
    {
        // A club whose league (BOFC) has no catalogued windows → AURA default.
        $user = $this->clubUser('BFC');

        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();
        self::assertSame('AURA', $data['league']);
        self::assertNotEmpty($data['items']);
    }

    public function testCatalogIsSharedAcrossClubs(): void
    {
        // Two clubs, same league, see the very same rows — the catalog is global,
        // not tenant-scoped (no club_id in the payload).
        $userA = $this->clubUser('AURA');
        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($userA));
        $idsA = array_column($this->responseData()['items'], 'id');

        $userB = $this->clubUser('AURA');
        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($userB));
        $idsB = array_column($this->responseData()['items'], 'id');

        self::assertSame($idsA, $idsB);
        self::assertNotEmpty($idsA);
    }

    public function testResolvedTeamWindowsUseTheServerJoin(): void
    {
        // P1-4 PR E2 (dette iv): the response resolves each team's envelope with
        // the SAME LeagueEnvelopeResolver the solver uses — a « U13 »
        // DEPARTEMENTAL team maps to the AURA U13 window, a category outside the
        // catalog resolves to [] (unmapped = advisory on screen, no HARD).
        $user = $this->clubUser('AURA');
        $club = $this->em->getRepository(ClubUser::class)->findOneBy(['userId' => $user->getId()]);
        \assert(null !== $club);
        $clubId = $club->getClubId();

        $season = new Season;
        $season->setClubId($clubId);
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basket ' . uniqid('', true));
        $sport->setSlug('basket-' . uniqid('', true));
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $mapped = $this->team($clubId, $season->getId(), $sport->getId(), 'U13', TeamLevel::DEPARTEMENTAL);
        $loisir = $this->team($clubId, $season->getId(), $sport->getId(), 'Loisir', TeamLevel::DEPARTEMENTAL);

        $this->client->request('GET', '/api/league-match-windows', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseData();

        $auraIds = array_column(array_values(array_filter($data['items'], static fn (array $item): bool => 'U13' === $item['category'])), 'id');
        self::assertSame($auraIds, $data['resolvedTeamWindows'][$mapped]);
        self::assertSame([], $data['resolvedTeamWindows'][$loisir]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Deterministic global fixture: one AURA row + one GEST row.
        $this->em->createQuery('DELETE FROM ' . LeagueMatchWindow::class . ' w')->execute();
        $this->window('AURA', 'U13', 6, '13:00', '18:00');
        $this->window('GEST', 'U13', 6, '14:00', '19:00');
        $this->em->flush();
    }

    private function team(string $clubId, string $seasonId, string $sportId, string $categoryName, TeamLevel $level): string
    {
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sportId);
        $category->setName($categoryName);
        $this->em->persist($category);
        $this->em->flush();

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('Équipe ' . $categoryName);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $team->setLevel($level);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    private function window(string $league, string $category, int $day, string $min, string $max): void
    {
        $w = new LeagueMatchWindow;
        $w->setLeague($league);
        $w->setCategory($category);
        $w->setLevel(LeagueMatchWindow::LEVEL_DEPARTEMENTAL);
        $w->setGender(null);
        $w->setDayOfWeek($day);
        $w->setKickoffMin(DateTimeImmutable::createFromFormat('!H:i', $min));
        $w->setKickoffMax(DateTimeImmutable::createFromFormat('!H:i', $max));
        $this->em->persist($w);
    }

    private function clubUser(string $ffbbPrefix): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $resolver = self::getContainer()->get(LeagueResolver::class);

        $club = new Club;
        $club->setName('Club catalog');
        $club->setSlug('club-catalog-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $ffbb = ('AURA' === $ffbbPrefix ? 'ARA' : $ffbbPrefix) . '00' . substr((string) crc32($uid), 0, 5);
        $club->setFfbbClubCode($ffbb);
        $club->setLeague($resolver->resolveFromFfbbCode($ffbb));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('catalog' . $uid . '@test.com');
        $user->setFirstName('Cat');
        $user->setLastName('Alog');
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
        $this->em->flush();

        return $user;
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
