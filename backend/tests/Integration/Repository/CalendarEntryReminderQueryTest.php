<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Repository\CalendarEntryRepository;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findUpcomingPeriodsWithoutOverlay feeds the reminder cron: [today, today+14]
 * window, period + active + no-overlay only, tenant-scoped.
 */
#[Group('phase1')]
#[Group('integration')]
final class CalendarEntryReminderQueryTest extends KernelTestCase
{
    use TenantGucTrait;

    private const TODAY = '2026-04-30';
    private const IN_WINDOW = '2026-05-04'; // today + 4

    private EntityManagerInterface $em;

    private CalendarEntryRepository $repo;

    public function testMatchesOnlyOverlayCapablePeriodTypes(): void
    {
        // Only closure/holiday can carry an overlay plan (ScheduleStateProcessor
        // refuses the others with 422) — reminding about a cutoff would CTA into
        // a dead end, and a cutoff ("no training") needs no plan anyway.
        [$club, $season] = $this->seed('Q1');
        foreach ([CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY, CalendarEntryPeriodType::CUTOFF, CalendarEntryPeriodType::CUSTOM] as $type) {
            $this->entry($club, $season, self::IN_WINDOW, kind: CalendarEntryKind::PERIOD, periodType: $type);
        }
        $this->em->flush();

        $rows = $this->query($club, $season);
        self::assertCount(2, $rows);
        $types = array_map(static fn (CalendarEntry $e): ?CalendarEntryPeriodType => $e->getPeriodType(), $rows);
        self::assertContains(CalendarEntryPeriodType::CLOSURE, $types);
        self::assertContains(CalendarEntryPeriodType::HOLIDAY, $types);
    }

    public function testExcludesOverlayEventStatusWindowAndSeason(): void
    {
        [$club, $season] = $this->seed('Q2');
        $this->entry($club, $season, self::IN_WINDOW, overlay: true); // has plan
        $this->entry($club, $season, self::IN_WINDOW, kind: CalendarEntryKind::EVENT); // event
        $this->entry($club, $season, self::IN_WINDOW, status: CalendarEntryStatus::PROPOSED); // proposed
        $this->entry($club, $season, self::IN_WINDOW, status: CalendarEntryStatus::IGNORED); // ignored
        $this->entry($club, $season, '2026-04-29'); // already started (before today)
        $this->entry($club, $season, '2026-05-20'); // beyond the 14-day horizon
        // Another season, same club, in window → excluded.
        $other = $this->season($club, 'archived');
        $this->entry($club, $other, self::IN_WINDOW);
        $this->em->flush();

        self::assertSame([], $this->query($club, $season));
    }

    public function testWindowBounds(): void
    {
        [$club, $season] = $this->seed('Q3');
        $this->entry($club, $season, self::TODAY); // day 0 → included
        $this->entry($club, $season, '2026-05-14'); // today+14 → included
        $this->entry($club, $season, '2026-05-15'); // today+15 → excluded
        $this->em->flush();

        self::assertCount(2, $this->query($club, $season));
    }

    public function testOtherClubEntryInvisible(): void
    {
        [$clubA, $seasonA] = $this->seed('Q4A');
        [$clubB, $seasonB] = $this->seed('Q4B');
        $this->entry($clubB, $seasonB, self::IN_WINDOW);
        $this->em->flush();

        $this->scopeGucToClub($clubA->getId());
        self::assertSame([], $this->repo->findUpcomingPeriodsWithoutOverlay($clubA->getId(), $seasonA->getId(), new DateTimeImmutable(self::TODAY), 14));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(CalendarEntryRepository::class);
    }

    /**
     * @return list<CalendarEntry>
     */
    private function query(Club $club, Season $season): array
    {
        return $this->repo->findUpcomingPeriodsWithoutOverlay($club->getId(), $season->getId(), new DateTimeImmutable(self::TODAY), 14);
    }

    private function entry(
        Club $club,
        Season $season,
        string $start,
        CalendarEntryKind $kind = CalendarEntryKind::PERIOD,
        ?CalendarEntryPeriodType $periodType = CalendarEntryPeriodType::CLOSURE,
        CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE,
        bool $overlay = false,
    ): void {
        $this->scopeGucToClub($club->getId());
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind($kind);
        $entry->setPeriodType(CalendarEntryKind::EVENT === $kind ? null : $periodType);
        $entry->setStatus($status);
        $entry->setTitle('P');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($start));
        $this->em->persist($entry);
        $this->em->flush();

        if ($overlay) {
            // lot D-b : « a un overlay » = le plan de la période porte ≥ 1 version (une
            // génération a eu lieu), plus un pointeur sur l'entrée. Le rappel s'arrête là.
            $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
            $planId = $provisioner->provisionPeriodPlan($entry->getId());
            self::assertIsString($planId, 'une closure/holiday porte un plan overlayable');
            $schedule = (new Schedule)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setName('Overlay')
                ->setStatus(ScheduleStatus::COMPLETED)
                ->setSchedulePlanId($planId);
            $this->em->persist($schedule);
            $provisioner->linkSchedule($schedule);
            $this->em->flush();
        }
    }

    private function season(Club $club, string $status): Season
    {
        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('S-' . $status);
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus($status);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('u-' . $tag . '-' . $uid . '@t.fr');
        $user->setFirstName('X');
        $user->setLastName('Y');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        return [$club, $this->season($club, 'active')];
    }
}
