<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
final class TeamApiTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $passwordHasher;

    private Club $club;

    private User $user;

    private Season $season;

    private Sport $sport;

    private SportCategory $sportCategory;

    private PriorityTier $priorityTier;

    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    public function testCreateTeam(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'U11 Boys',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'gender' => 'M',
            'sessionsPerWeek' => 2,
            'isActive' => true,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertSame('U11 Boys', $data['name']);
        self::assertSame('M', $data['gender']);
        self::assertSame(2, $data['sessionsPerWeek']);
        self::assertTrue($data['isActive']);
    }

    public function testTierOrderIsWritableOnCreate(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Ranked',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'tierOrder' => 5,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(5, json_decode((string) $client->getResponse()->getContent(), true)['tierOrder']);
    }

    public function testTierOrderIsWritableOnUpdate(): void
    {
        $client = $this->client;
        $team = $this->createTeam('Ranked');
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Ranked',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'tierOrder' => 2,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame(2, json_decode((string) $client->getResponse()->getContent(), true)['tierOrder']);
    }

    public function testListTeams(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $this->createTeam('Team Alpha');
        $this->createTeam('Team Beta');

        $client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('member', $data);
        self::assertCount(2, $data['member']);
    }

    public function testGetTeam(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $team = $this->createTeam('Test Team');

        $client->request('GET', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($team->getId(), $data['id']);
        self::assertSame('Test Team', $data['name']);
    }

    public function testUpdateTeam(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $team = $this->createTeam('Original Name');

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Updated Name',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'gender' => 'F',
            'sessionsPerWeek' => 3,
            'isActive' => false,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Updated Name', $data['name']);
        self::assertSame('F', $data['gender']);
        self::assertSame(3, $data['sessionsPerWeek']);
        self::assertFalse($data['isActive']);
    }

    public function testDeleteTeam(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $team = $this->createTeam('To Delete');
        $teamId = $team->getId();

        $client->request('DELETE', \sprintf('/api/teams/%s', $teamId), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(204);

        $deleted = $this->em->getRepository(Team::class)->find($teamId);
        self::assertNull($deleted);
    }

    public function testTenantIsolation(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $otherClub = $this->createClub('Other Club');
        $otherSeason = $this->createSeason($otherClub);
        $otherSportCategory = $this->createSportCategory($otherClub, $this->sport);
        $otherTeam = new Team;
        $otherTeam->setClubId($otherClub->getId());
        $otherTeam->setSeasonId($otherSeason->getId());
        $otherTeam->setSportCategoryId($otherSportCategory->getId());
        $otherTeam->setPriorityTierId($this->priorityTier->getId());
        $otherTeam->setName('Other Club Team');
        $otherTeam->setSessionsPerWeek(2);
        $otherTeam->setIsActive(true);
        $this->em->persist($otherTeam);
        $this->em->flush();

        $ownTeam = $this->createTeam('Own Team');

        $client->request('GET', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
        ]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('member', $data);
        self::assertCount(1, $data['member']);
        self::assertSame($ownTeam->getId(), $data['member'][0]['id']);
    }

    public function testBulkReorderPersistsTierAndOrderAtomically(): void
    {
        $client = $this->client;
        $a = $this->createTeam('Alpha');
        $b = $this->createTeam('Bravo');
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams/reorder', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['items' => [
            ['id' => $a->getId(), 'priorityTierId' => $this->priorityTier->getId(), 'tierOrder' => 3],
            ['id' => $b->getId(), 'priorityTierId' => $this->priorityTier->getId(), 'tierOrder' => 1],
        ]], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame(3, $this->em->getRepository(Team::class)->find($a->getId())?->getTierOrder());
        self::assertSame(1, $this->em->getRepository(Team::class)->find($b->getId())?->getTierOrder());
    }

    public function testLevelIsWritableAndReadable(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Regional Team',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'level' => 'REGIONAL',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('REGIONAL', json_decode((string) $client->getResponse()->getContent(), true)['level']);
    }

    public function testLevelIsClearedWhenNullOnUpdate(): void
    {
        $client = $this->client;
        $team = $this->createTeam('Leveled');
        $team->setLevel(\App\Enum\TeamLevel::REGIONAL);
        $this->em->flush();
        $client->loginUser($this->user);

        $client->request('PUT', \sprintf('/api/teams/%s', $team->getId()), [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Leveled',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'level' => null,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['level']);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Team::class)->find($team->getId())?->getLevel());
    }

    public function testInvalidLevelIsRejected(): void
    {
        $client = $this->client;
        $client->loginUser($this->user);

        $client->request('POST', '/api/teams', [], [], [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Bad Level',
            'sportCategoryId' => $this->sportCategory->getId(),
            'priorityTierId' => $this->priorityTier->getId(),
            'level' => 'REGIONALE',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Team Test Club');
        $this->club->setSlug('team-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('TMT' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->sport = new Sport;
        $this->sport->setName('Basketball');
        $this->sport->setSlug('bball-' . $uid);
        $this->sport->setIsActive(true);
        $this->em->persist($this->sport);

        $this->user = new User;
        $this->user->setEmail('team' . $uid . '@test.com');
        $this->user->setFirstName('Team');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($this->passwordHasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus('active');
        $this->em->persist($this->season);

        $this->sportCategory = new SportCategory;
        $this->sportCategory->setClubId($this->club->getId());
        $this->sportCategory->setSportId($this->sport->getId());
        $this->sportCategory->setName('U11');
        $this->sportCategory->setIsCustom(false);
        $this->sportCategory->setSortOrder(0);
        $this->em->persist($this->sportCategory);

        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            $this->priorityTier = $existing;
        } else {
            $this->priorityTier = new PriorityTier;
            $this->priorityTier->setId(1);
            $this->priorityTier->setLabel('S');
            $this->priorityTier->setName('Senior');
            $this->priorityTier->setColor('#FF0000');
            $this->priorityTier->setOrToolsWeight(100);
            $this->priorityTier->setDefaultMinSessions(2);
            $this->em->persist($this->priorityTier);
        }

        $this->em->flush();
    }

    private function createClub(string $name = 'Test Club'): Club
    {
        $club = new Club;
        $club->setName($name . ' ' . uniqid());
        $club->setSlug('test-club-' . uniqid());
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function createUser(): User
    {
        $user = new User;
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, 'password123'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createClubUser(Club $club, User $user): void
    {
        $this->scopeGucToClub($club->getId());
        $clubUser = new ClubUser;
        $clubUser->setClubId($club->getId());
        $clubUser->setUserId($user->getId());
        $clubUser->setRole('admin');
        $clubUser->setIsActive(true);

        $this->em->persist($clubUser);
        $this->em->flush();
    }

    private function createSeason(Club $club): Season
    {
        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus('active');
        $season->setTransitionData([]);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function createSport(): Sport
    {
        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('basketball-' . uniqid());
        $sport->setIsActive(true);

        $this->em->persist($sport);
        $this->em->flush();

        return $sport;
    }

    private function createSportCategory(Club $club, Sport $sport): SportCategory
    {
        $this->scopeGucToClub($club->getId());
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function createPriorityTier(): PriorityTier
    {
        $existing = $this->em->getRepository(PriorityTier::class)->find(1);
        if ($existing instanceof PriorityTier) {
            return $existing;
        }

        $tier = new PriorityTier;
        $tier->setId(1);
        $tier->setLabel('S');
        $tier->setName('Senior');
        $tier->setColor('#FF0000');
        $tier->setOrToolsWeight(100);
        $tier->setDefaultMinSessions(2);

        $this->em->persist($tier);
        $this->em->flush();

        return $tier;
    }

    private function createTeam(string $name): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->sportCategory->getId());
        $team->setPriorityTierId($this->priorityTier->getId());
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);

        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
