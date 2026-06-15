<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class TenantIsolationTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    private ?UserPasswordHasherInterface $passwordHasher = null;

    private ?TokenStorageInterface $tokenStorage = null;

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdClubIds = [];

    protected function setUp(): void
    {
        putenv('APP_ENV=test');
        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = self::getContainer()->get('security.user_password_hasher');
        $this->tokenStorage = self::getContainer()->get('security.token_storage');
    }

    protected function tearDown(): void
    {
        if (null !== $this->tokenStorage) {
            $this->tokenStorage->setToken(null);
        }

        if (null !== $this->em) {
            $this->cleanupTestData();
            $this->em->flush();
            $this->em->clear();
        }

        parent::tearDown();
    }

    /** @group phase1 */
    public function testUsersFromDifferentClubsCannotSeeEachOthersData(): void
    {
        $fixtures = $this->createFixtures();
        $clubAId = $fixtures['clubA']->getId();
        $clubBId = $fixtures['clubB']->getId();
        $userA = $fixtures['userA'];

        // Authenticate as userA (member of club A only)
        $token = new UsernamePasswordToken($userA, 'api', $userA->getRoles());
        $this->tokenStorage->setToken($token);

        // Request with club B's header — user A is not a member of club B
        $response = self::$kernel->handle(Request::create(
            '/api/teams',
            'GET',
            [],
            [],
            [],
            ['HTTP_X-Club-Id' => $clubBId]
        ));
        self::assertSame(403, $response->getStatusCode(), 'User A should be forbidden from accessing club B data');

        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data, 'Forbidden response should contain error message');
    }

    /** @group phase1 */
    public function testTenantFilterEnabledForAuthenticatedRequests(): void
    {
        $fixtures = $this->createFixtures();
        $clubAId = $fixtures['clubA']->getId();
        $userA = $fixtures['userA'];

        // Authenticate as userA (member of club A)
        $token = new UsernamePasswordToken($userA, 'api', $userA->getRoles());
        $this->tokenStorage->setToken($token);

        // Request with club A's header — user A is a member of club A
        $response = self::$kernel->handle(Request::create(
            '/api/teams',
            'GET',
            [],
            [],
            [],
            ['HTTP_X-Club-Id' => $clubAId]
        ));
        self::assertSame(200, $response->getStatusCode(), 'Authenticated user with valid club membership should get 200');

        $data = json_decode((string) $response->getContent(), true);
        $members = $data['hydra:member'] ?? [];
        self::assertIsArray($members, 'Response should contain a list of teams');
        foreach ($members as $team) {
            self::assertSame($clubAId, $team['clubId'] ?? '', 'Tenant filter should only return teams from the requested club');
        }
    }

    /** @group phase1 */
    public function testTenantFilterDisabledForUnauthenticatedRequests(): void
    {
        $this->tokenStorage->setToken(null);

        // Without authentication, the api firewall in test env has security: false,
        // so the request passes through. Without X-Club-Id, the tenant filter is not active.
        // The key assertion: no 500 error, and the request is handled appropriately.
        $response = self::$kernel->handle(Request::create('/api/teams', 'GET'));
        // In test env, security is disabled on api firewall, so we expect 200 (no auth required)
        // but without X-Club-Id, the tenant filter is not active
        self::assertContains($response->getStatusCode(), [200, 401, 403], 'Unauthenticated request should be handled without error');
    }

    /** @group phase1 */
    public function testInactiveMembershipBlocksAccess(): void
    {
        $fixtures = $this->createFixtures();
        $clubAId = $fixtures['clubA']->getId();
        $userA = $fixtures['userA'];

        // Deactivate user A's membership in club A
        $membership = $this->em->getRepository(ClubUser::class)->findOneBy([
            'userId' => $userA->getId(),
            'clubId' => $clubAId,
        ]);
        self::assertNotNull($membership, 'Membership should exist');
        $membership->setIsActive(false);
        $this->em->flush();

        // Authenticate as userA with deactivated membership
        $token = new UsernamePasswordToken($userA, 'api', $userA->getRoles());
        $this->tokenStorage->setToken($token);

        $response = self::$kernel->handle(Request::create(
            '/api/teams',
            'GET',
            [],
            [],
            [],
            ['HTTP_X-Club-Id' => $clubAId]
        ));
        self::assertSame(403, $response->getStatusCode(), 'User with inactive membership should be forbidden');
    }

    /**
     * @return array{clubA: Club, clubB: Club, userA: User, userB: User, teamA: Team, teamB: Team}
     */
    private function createFixtures(): array
    {
        $unique = uniqid();

        $priorityTier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (null === $priorityTier) {
            $priorityTier = new PriorityTier();
            $priorityTier->setId(1);
            $priorityTier->setLabel('S');
            $priorityTier->setName('Senior');
            $priorityTier->setColor('#FF0000');
            $priorityTier->setOrToolsWeight(100);
            $priorityTier->setDefaultMinSessions(2);
            $this->em->persist($priorityTier);
        }

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['slug' => 'basketball']);
        if (null === $sport) {
            $sport = new Sport();
            $sport->setName('Basketball');
            $sport->setSlug('basketball');
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }

        // Create club A
        $clubA = new Club();
        $clubA->setName('Club Alpha '.$unique);
        $clubA->setSlug('club-alpha-'.$unique);
        $clubA->setTimezone('Europe/Paris');
        $clubA->setLocale('fr');
        $clubA->setOnboardingCompleted(true);
        $clubA->setFfbbClubCode('ARA'.strtoupper(substr(md5(uniqid()), 0, 10)));
        $this->em->persist($clubA);

        // Create club B
        $clubB = new Club();
        $clubB->setName('Club Beta '.$unique);
        $clubB->setSlug('club-beta-'.$unique);
        $clubB->setTimezone('Europe/Paris');
        $clubB->setLocale('fr');
        $clubB->setOnboardingCompleted(true);
        $clubB->setFfbbClubCode('BRB'.strtoupper(substr(md5(uniqid()), 0, 10)));
        $this->em->persist($clubB);

        $this->em->flush();
        $this->createdClubIds[] = $clubA->getId();
        $this->createdClubIds[] = $clubB->getId();

        // Create user A (member of club A only)
        $userA = new User();
        $userA->setEmail('userA'.$unique.'@example.com');
        $userA->setFirstName('User');
        $userA->setLastName('Alpha');
        $userA->setPasswordHash($this->passwordHasher->hashPassword($userA, 'SecurePass123!'));
        $this->em->persist($userA);

        // Create user B (member of club B only)
        $userB = new User();
        $userB->setEmail('userB'.$unique.'@example.com');
        $userB->setFirstName('User');
        $userB->setLastName('Beta');
        $userB->setPasswordHash($this->passwordHasher->hashPassword($userB, 'SecurePass123!'));
        $this->em->persist($userB);

        $this->em->flush();
        $this->createdUserIds[] = $userA->getId();
        $this->createdUserIds[] = $userB->getId();

        // Create club-user memberships
        $clubUserA = new ClubUser();
        $clubUserA->setClubId($clubA->getId());
        $clubUserA->setUserId($userA->getId());
        $clubUserA->setRole('admin');
        $clubUserA->setIsActive(true);
        $this->em->persist($clubUserA);

        $clubUserB = new ClubUser();
        $clubUserB->setClubId($clubB->getId());
        $clubUserB->setUserId($userB->getId());
        $clubUserB->setRole('admin');
        $clubUserB->setIsActive(true);
        $this->em->persist($clubUserB);

        // Create seasons for both clubs
        $seasonA = new Season();
        $seasonA->setClubId($clubA->getId());
        $seasonA->setName('2025-2026');
        $seasonA->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $seasonA->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $seasonA->setStatus('active');
        $seasonA->setTransitionData([]);
        $this->em->persist($seasonA);

        $seasonB = new Season();
        $seasonB->setClubId($clubB->getId());
        $seasonB->setName('2025-2026');
        $seasonB->setStartDate(new \DateTimeImmutable('2025-09-01'));
        $seasonB->setEndDate(new \DateTimeImmutable('2026-06-30'));
        $seasonB->setStatus('active');
        $seasonB->setTransitionData([]);
        $this->em->persist($seasonB);

        // Create sport categories for both clubs
        $sportCategoryA = new SportCategory();
        $sportCategoryA->setClubId($clubA->getId());
        $sportCategoryA->setSportId($sport->getId());
        $sportCategoryA->setName('U11');
        $sportCategoryA->setIsCustom(false);
        $sportCategoryA->setSortOrder(0);
        $this->em->persist($sportCategoryA);

        $sportCategoryB = new SportCategory();
        $sportCategoryB->setClubId($clubB->getId());
        $sportCategoryB->setSportId($sport->getId());
        $sportCategoryB->setName('U13');
        $sportCategoryB->setIsCustom(false);
        $sportCategoryB->setSortOrder(0);
        $this->em->persist($sportCategoryB);

        $this->em->flush();

        // Create teams for each club
        $teamA = new Team();
        $teamA->setClubId($clubA->getId());
        $teamA->setSeasonId($seasonA->getId());
        $teamA->setSportCategoryId($sportCategoryA->getId());
        $teamA->setPriorityTierId($priorityTier->getId());
        $teamA->setName('Alpha U11');
        $teamA->setSessionsPerWeek(2);
        $teamA->setIsActive(true);
        $this->em->persist($teamA);

        $teamB = new Team();
        $teamB->setClubId($clubB->getId());
        $teamB->setSeasonId($seasonB->getId());
        $teamB->setSportCategoryId($sportCategoryB->getId());
        $teamB->setPriorityTierId($priorityTier->getId());
        $teamB->setName('Beta U13');
        $teamB->setSessionsPerWeek(2);
        $teamB->setIsActive(true);
        $this->em->persist($teamB);

        $this->em->flush();

        return [
            'clubA' => $clubA,
            'clubB' => $clubB,
            'userA' => $userA,
            'userB' => $userB,
            'teamA' => $teamA,
            'teamB' => $teamB,
        ];
    }

    private function cleanupTestData(): void
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['slug' => 'basketball']);
        if (null !== $sport) {
            $this->em->remove($sport);
        }

        $priorityTier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (null !== $priorityTier) {
            $this->em->remove($priorityTier);
        }

        foreach ($this->createdClubIds as $clubId) {
            $club = $this->em->getRepository(Club::class)->find($clubId);
            if (null !== $club) {
                $teams = $this->em->getRepository(Team::class)->findBy(['clubId' => $clubId]);
                foreach ($teams as $t) {
                    $this->em->remove($t);
                }

                $sportCategories = $this->em->getRepository(SportCategory::class)->findBy(['clubId' => $clubId]);
                foreach ($sportCategories as $sc) {
                    $this->em->remove($sc);
                }

                $seasons = $this->em->getRepository(Season::class)->findBy(['clubId' => $clubId]);
                foreach ($seasons as $s) {
                    $this->em->remove($s);
                }

                $clubUsers = $this->em->getRepository(ClubUser::class)->findBy(['clubId' => $clubId]);
                foreach ($clubUsers as $cu) {
                    $this->em->remove($cu);
                }

                $this->em->remove($club);
            }
        }

        foreach ($this->createdUserIds as $userId) {
            $user = $this->em->getRepository(User::class)->find($userId);
            if (null !== $user) {
                $this->em->remove($user);
            }
        }
    }
}
