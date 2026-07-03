<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Designating a finished schedule as the season main plan (baseline); a finished
 * plan is COMPLETED or VALIDATED; only within the caller's own club.
 */
#[Group('phase1')]
#[Group('integration')]
final class SetBaselineTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testSetBaselineDesignatesMainPlan(): void
    {
        [$user, , $season] = $this->seed('BASE1');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/set-baseline");

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Season::class)->find($season->getId());
        self::assertSame($schedule->getId(), $reloaded?->getBaselineScheduleId());
    }

    public function testValidatedScheduleCanBeMainPlan(): void
    {
        [$user, , $season] = $this->seed('BASE2');
        $schedule = $this->createSchedule($season, ScheduleStatus::VALIDATED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/set-baseline");

        self::assertResponseIsSuccessful();
    }

    public function testDraftScheduleCannotBeMainPlan(): void
    {
        [$user, , $season] = $this->seed('BASE3');
        $schedule = $this->createSchedule($season, ScheduleStatus::DRAFT);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/set-baseline");

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignScheduleIsNotAccessible(): void
    {
        [$user] = $this->seed('BASE4');
        [, , $otherSeason] = $this->seed('BASE5');
        $foreign = $this->createSchedule($otherSeason, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$foreign->getId()}/set-baseline");

        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
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
        $user->setFirstName('B');
        $user->setLastName('SE');
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

    private function createSchedule(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }
}
