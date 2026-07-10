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
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Validating a COMPLETED schedule locks it (→ VALIDATED, read-only); only within
 * the caller's own club, and only when completed.
 *
 * Version model (§7.1 planning lifecycle, specs/evolution/planning-versions.md):
 * validating a SEASON plan also makes it the season baseline and ARCHIVES its
 * sibling season-plan versions — never the overlays; a sibling still generating
 * blocks the validation (409).
 */
#[Group('phase1')]
#[Group('integration')]
final class ValidateScheduleTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testValidateLocksCompletedSchedule(): void
    {
        [$user, , $season] = $this->seed('VAL1');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Schedule::class)->find($schedule->getId());
        self::assertSame(ScheduleStatus::VALIDATED, $reloaded?->getStatus());
    }

    public function testValidatingBaselineStampsStickyCockpitUnlock(): void
    {
        [$user, , $season] = $this->seed('VAL5');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $season->setBaselineScheduleId($schedule->getId());
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(Season::class)->find($season->getId());
        self::assertNotNull($reloaded?->getSocleValidatedAt(), 'validating the baseline must stamp socleValidatedAt');

        // Surfaced to the frontend via /api/me (stateless firewall → Bearer).
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->client->request('GET', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt->create($user),
        ]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotNull($me['socleValidatedAt']);
    }

    public function testValidatingSeasonPlanBecomesBaselineAndStampsUnlock(): void
    {
        // Version model: "this version IS the plan" — validating a season plan
        // that was NOT the baseline makes it the baseline and unlocks the cockpit.
        [$user, , $season] = $this->seed('VAL6');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(Season::class)->find($season->getId());
        self::assertSame($schedule->getId(), $reloaded?->getBaselineScheduleId(), 'the validated version becomes the baseline');
        self::assertNotNull($reloaded?->getSocleValidatedAt());
    }

    public function testValidateArchivesSiblingSeasonPlansButNotOverlays(): void
    {
        [$user, , $season] = $this->seed('VAL7');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $failed = $this->createSchedule($season, ScheduleStatus::FAILED);
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, '44444444-4444-4444-8444-444444444444');
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(ScheduleStatus::VALIDATED, $this->em->getRepository(Schedule::class)->find($v2->getId())?->getStatus());
        self::assertSame(ScheduleStatus::ARCHIVED, $this->em->getRepository(Schedule::class)->find($v1->getId())?->getStatus(), 'sibling COMPLETED version archived');
        self::assertSame(ScheduleStatus::ARCHIVED, $this->em->getRepository(Schedule::class)->find($failed->getId())?->getStatus(), 'sibling FAILED version archived');
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($overlay->getId())?->getStatus(), 'an overlay is NEVER archived by season-plan validation');
    }

    public function testValidateBlockedWhileSiblingIsGenerating(): void
    {
        [$user, , $season] = $this->seed('VAL8');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->createSchedule($season, ScheduleStatus::GENERATING);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate");

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v1->getId())?->getStatus(), 'nothing committed when a sibling is mid-solve');
    }

    public function testNonCompletedScheduleCannotBeValidated(): void
    {
        [$user, , $season] = $this->seed('VAL2');
        $schedule = $this->createSchedule($season, ScheduleStatus::DRAFT);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignScheduleIsNotAccessible(): void
    {
        [$user] = $this->seed('VAL3');
        [, , $otherSeason] = $this->seed('VAL4');
        $foreign = $this->createSchedule($otherSeason, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$foreign->getId()}/validate");

        // The controller guard rejects a schedule from another club (the caller's
        // club is resolved from the JWT); RLS is a second line in production.
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
        $user->setLastName('L3');
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

    private function createSchedule(Season $season, ScheduleStatus $status, ?string $calendarEntryId = null): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        $schedule->setCalendarEntryId($calendarEntryId);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }
}
