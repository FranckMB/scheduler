<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Service\StructureSnapshotter;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * planning-versions (§7.1): POST /api/schedules/{id}/regenerate-from ("Charger
 * cette version") restores a version's structure photo and re-points the season's
 * loaded context (★) to it — WITHOUT solving (no new version). Refused for an
 * overlay and for a version with no photo (pre-D2).
 */
#[Group('phase1')]
final class RegenerateFromVersionTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testLoadVersionRestoresStructureAndRepointsLiveContextWithoutSolving(): void
    {
        $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($v1, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        // A later version V2 is the current loaded context; the structure diverges (add a team).
        $v2 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->season->setLiveContextScheduleId($v2->getId());
        $this->persistTeam('SM2');
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        // Structure restored to V1 (only SM1) — and NO new version was created.
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['seasonId' => $this->season->getId()]));
        $schedules = $this->em->getRepository(Schedule::class)->findBy(['seasonId' => $this->season->getId(), 'calendarEntryId' => null]);
        self::assertCount(2, $schedules, 'no new version — only V1 and V2 remain');
        // The ★ moved to V1 (the loaded context).
        self::assertSame($v1->getId(), $this->em->getRepository(Season::class)->find($this->season->getId())?->getLiveContextScheduleId());
    }

    public function testRegenerateFromANonCompletedSourceIsRefused(): void
    {
        // A VALIDATED (read-only) version's conditions cannot be replayed —
        // reopen it first (D1 model archives siblings on validate).
        $v1 = $this->makeSchedule(ScheduleStatus::VALIDATED, null);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegenerateFromBlockedWhileASiblingIsGenerating(): void
    {
        $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->makeSchedule(ScheduleStatus::GENERATING, null); // a sibling mid-solve
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($v1, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
        // Nothing wiped while a sibling solves.
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['seasonId' => $this->season->getId()]));
    }

    public function testRegenerateFromAVersionWithoutPhotoIsRefused(): void
    {
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null); // never snapshotted
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegenerateFromAnOverlayIsRefused(): void
    {
        $overlay = $this->makeSchedule(ScheduleStatus::COMPLETED, '44444444-4444-4444-8444-444444444444');
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$overlay->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * The Schedule API exposes `hasStructurePhoto` so the client offers "Charger
     * cette version" ONLY when the restore can succeed — decoupling the button
     * from generatedTeamCount (which every generated plan carries) fixes the
     * pre-D2 "flash error" (the button showed then 409'd on a photo-less plan).
     */
    public function testScheduleApiExposesHasStructurePhoto(): void
    {
        $this->persistTeam('SM1');
        $withPhoto = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $withoutPhoto = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($withPhoto, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        $this->client->request('GET', "/api/schedules/{$withPhoto->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode()['hasStructurePhoto'], 'a snapshotted version carries a restorable photo');

        $this->client->request('GET', "/api/schedules/{$withoutPhoto->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode()['hasStructurePhoto'], 'a photo-less version must not offer the restore');
    }

    /**
     * The Schedule API exposes `isLiveContext` (★) — true only for the version the
     * season points at as its loaded context, so the star tracks the loaded
     * context regardless of which version is being viewed.
     */
    public function testScheduleApiExposesIsLiveContext(): void
    {
        $live = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $other = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->season->setLiveContextScheduleId($live->getId());
        $this->em->flush();

        $this->client->request('GET', "/api/schedules/{$live->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode()['isLiveContext'], 'the season-pointed version carries the ★');

        $this->client->request('GET', "/api/schedules/{$other->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode()['isLiveContext'], 'a non-pointed version is not the loaded context');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Regen ' . $uid)->setSlug('regen-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('regen' . $uid . '@test.com')->setFirstName('R')->setLastName('G');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    private function persistTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName($name)->setSessionsPerWeek(1)->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    private function makeSchedule(ScheduleStatus $status, ?string $calendarEntryId): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('V')->setStatus($status)->setCalendarEntryId($calendarEntryId);
        $this->em->persist($schedule);

        return $schedule;
    }
}
