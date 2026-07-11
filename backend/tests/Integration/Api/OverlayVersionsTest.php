<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * planning-versions (overlay versions) non-regression: a period's overlay
 * follows the SAME version lifecycle as a season plan — validating one version
 * archives its siblings OF THE SAME PERIOD and makes it the active overlay, while
 * leaving other periods' overlays, season plans and the season baseline/socle
 * untouched; an in-flight sibling blocks validation; reopening does not
 * resurrect archived siblings.
 */
#[Group('phase1')]
#[Group('integration')]
final class OverlayVersionsTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private JWTTokenManagerInterface $jwt;

    public function testValidatingOverlayVersionArchivesSiblingsAndSetsActive(): void
    {
        [$user, $club, $season, $baseline] = $this->seed('OVV1');
        $entry = $this->period($club, $season, 'P1');
        $otherEntry = $this->period($club, $season, 'P2');

        $v1 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $v2 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $entry->setOverlayScheduleId($v1->getId()); // V1 active initially
        $otherOverlay = $this->overlay($club, $season, $otherEntry, ScheduleStatus::COMPLETED);
        $otherEntry->setOverlayScheduleId($otherOverlay->getId());
        // A non-baseline SEASON plan version must not be touched by an overlay validation.
        $seasonPlan = $this->seasonPlan($club, $season, ScheduleStatus::COMPLETED);
        $this->em->flush();

        $this->post($user, $club, "/api/schedules/{$v2->getId()}/validate");
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        // V2 validated + active overlay; V1 archived.
        self::assertSame(ScheduleStatus::VALIDATED, $this->em->getRepository(Schedule::class)->find($v2->getId())?->getStatus());
        self::assertSame(ScheduleStatus::ARCHIVED, $this->em->getRepository(Schedule::class)->find($v1->getId())?->getStatus());
        self::assertSame($v2->getId(), $this->em->getRepository(CalendarEntry::class)->find($entry->getId())?->getOverlayScheduleId());
        // The OTHER period's overlay is untouched.
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($otherOverlay->getId())?->getStatus());
        self::assertSame($otherOverlay->getId(), $this->em->getRepository(CalendarEntry::class)->find($otherEntry->getId())?->getOverlayScheduleId());
        // The season plan is untouched, and the season baseline/socle did NOT move.
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($seasonPlan->getId())?->getStatus());
        $reloadedSeason = $this->em->getRepository(Season::class)->find($season->getId());
        self::assertSame($baseline->getId(), $reloadedSeason?->getBaselineScheduleId(), 'validating an overlay must not move the season baseline');
    }

    public function testInFlightSiblingOverlayBlocksValidation(): void
    {
        [$user, $club, $season] = $this->seed('OVV2');
        $entry = $this->period($club, $season, 'P');
        $v1 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $this->overlay($club, $season, $entry, ScheduleStatus::GENERATING); // sibling still solving
        $this->em->flush();

        $this->post($user, $club, "/api/schedules/{$v1->getId()}/validate");
        self::assertResponseStatusCodeSame(409, 'a sibling overlay still generating blocks validation');
    }

    public function testReopenOverlayDoesNotResurrectArchivedSiblings(): void
    {
        [$user, $club, $season] = $this->seed('OVV3');
        $entry = $this->period($club, $season, 'P');
        $archived = $this->overlay($club, $season, $entry, ScheduleStatus::ARCHIVED);
        $validated = $this->overlay($club, $season, $entry, ScheduleStatus::VALIDATED);
        $entry->setOverlayScheduleId($validated->getId());
        $this->em->flush();

        $this->post($user, $club, "/api/schedules/{$validated->getId()}/reopen");
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($validated->getId())?->getStatus());
        self::assertSame(ScheduleStatus::ARCHIVED, $this->em->getRepository(Schedule::class)->find($archived->getId())?->getStatus(), 'reopen must not resurrect an archived sibling');
        self::assertSame($validated->getId(), $this->em->getRepository(CalendarEntry::class)->find($entry->getId())?->getOverlayScheduleId());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    private function post(User $user, Club $club, string $url): void
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ]);
    }

    private function overlay(Club $club, Season $season, CalendarEntry $entry, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('Overlay ' . $status->value)
            ->setStatus($status)
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($schedule);

        return $schedule;
    }

    private function seasonPlan(Club $club, Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('Plan saison')
            ->setStatus($status);
        $this->em->persist($schedule);

        return $schedule;
    }

    private function period(Club $club, Season $season, string $title): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle($title);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season, 3: Schedule}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');

        $club = (new Club)->setName('Club ' . $tag)->setSlug('club-' . $tag . '-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = (new User)->setEmail('u-' . $tag . '-' . $uid . '@test.com')->setFirstName('O')->setLastName('V');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus('active');
        $this->em->persist($season);
        $this->em->flush();

        $baseline = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Baseline')->setStatus(ScheduleStatus::VALIDATED);
        $this->em->persist($baseline);
        $this->em->flush();
        $season->setBaselineScheduleId($baseline->getId());
        $season->setSocleValidatedAt(new DateTimeImmutable);
        $this->em->flush();

        return [$user, $club, $season, $baseline];
    }
}
