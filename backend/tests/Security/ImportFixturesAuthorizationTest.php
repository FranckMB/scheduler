<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SEC-04 non-regression for POST /api/teams/{id}/fixtures/import (module matchs
 * PR-4, §7.1 tenant axis): the FBI import writes into ONE team — a caller must
 * hold an active management membership in that team's club, a foreign team is
 * invisible (404, no existence oracle), and archived seasons refuse writes (409).
 */
#[Group('phase1')]
#[Group('integration')]
final class ImportFixturesAuthorizationTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testImportOnForeignTeamReturns404(): void
    {
        [$tokenA] = $this->register('FIXA');
        [, , $clubB] = $this->register('FIXB');
        $teamB = $this->createTeam($clubB);

        // The tenant filter hides club B's team from club A's caller → 404,
        // no cross-tenant existence oracle.
        $this->client->request('POST', '/api/teams/' . $teamB->getId() . '/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(404, 'a non-member must not learn the team exists');
    }

    public function testImportAsActiveAdminReaches400WithoutFile(): void
    {
        [$tokenA, , $clubA] = $this->register('FIXC');
        $team = $this->createTeam($clubA);

        // Guard passed → falls through to "No file uploaded" (400).
        $this->client->request('POST', '/api/teams/' . $team->getId() . '/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testImportAsNonAdminMemberReturns403(): void
    {
        [, , $clubA] = $this->register('FIXD');
        $team = $this->createTeam($clubA);
        $editorToken = $this->addActiveMember($clubA, 'editor');

        $this->client->request('POST', '/api/teams/' . $team->getId() . '/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken,
        ]);
        self::assertResponseStatusCodeSame(403, 'a non-management member must not import');
    }

    public function testImportOnArchivedSeasonReturns409(): void
    {
        [$tokenA, , $clubA] = $this->register('FIXE');
        // A PAST season → archived (read-only) → its team refuses the write.
        // Register seeds a civil-year season (possibly a future bin before the
        // July-15 pivot) — anchor the TRUE current season so the past one is
        // actually archived rather than falling back to "latest started".
        $this->scopeGucToClub($clubA);
        $currentYear = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->createSeason($clubA, $currentYear);
        $past = $this->createSeason($clubA, $currentYear - 1);
        $team = $this->createTeam($clubA, $past->getId());

        $this->client->request('POST', '/api/teams/' . $team->getId() . '/fixtures/import', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            'HTTP_X-Season-Id' => $past->getId(),
        ]);
        self::assertResponseStatusCodeSame(409, 'archived-season writes must be refused');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createSeason(string $clubId, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus('active');
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function createTeam(string $clubId, ?string $seasonId = null): Team
    {
        $this->scopeGucToClub($clubId);
        if (null === $seasonId) {
            $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId])
                ?? $this->createSeason($clubId, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
            $seasonId = $season->getId();
        }

        $sport = $this->em->getRepository(\App\Entity\Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new \App\Entity\Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new \App\Entity\SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, clubId]
     */
    private function register(string $ara): array
    {
        // High-entropy IP: the register rate-limiter lives in Redis and is NOT
        // rolled back between test runs.
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Import', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara,
        ], \JSON_THROW_ON_ERROR));

        $reg = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $reg['token'] ?? '';
        self::assertNotSame('', $token, 'registration must return a token');

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);

        return [$token, $me['id'], $me['club']['id']];
    }
}
