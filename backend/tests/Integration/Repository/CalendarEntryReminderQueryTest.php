<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Repository\CalendarEntryRepository;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findPeriodsWithoutOverlayStartingOn feeds the reminder cron: exact-date match,
 * period + active + no-overlay only, tenant-scoped.
 */
#[Group('phase1')]
#[Group('integration')]
final class CalendarEntryReminderQueryTest extends KernelTestCase
{
    use TenantGucTrait;

    private const TARGET = '2026-05-04';

    private EntityManagerInterface $em;

    private CalendarEntryRepository $repo;

    public function testMatchesActivePeriodsWithoutOverlayForEveryPeriodType(): void
    {
        [$club, $season] = $this->seed('Q1');
        foreach ([CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY, CalendarEntryPeriodType::CUTOFF, CalendarEntryPeriodType::CUSTOM] as $type) {
            $this->entry($club, $season, self::TARGET, kind: CalendarEntryKind::PERIOD, periodType: $type);
        }
        $this->em->flush();

        $rows = $this->repo->findPeriodsWithoutOverlayStartingOn($club->getId(), $season->getId(), new DateTimeImmutable(self::TARGET));
        self::assertCount(4, $rows);
    }

    public function testExcludesOverlayEventStatusDateAndSeason(): void
    {
        [$club, $season] = $this->seed('Q2');
        $this->entry($club, $season, self::TARGET, overlay: true); // has plan
        $this->entry($club, $season, self::TARGET, kind: CalendarEntryKind::EVENT); // event
        $this->entry($club, $season, self::TARGET, status: CalendarEntryStatus::PROPOSED); // proposed
        $this->entry($club, $season, self::TARGET, status: CalendarEntryStatus::IGNORED); // ignored
        $this->entry($club, $season, '2026-05-05'); // off by one day
        // Another season, same club, same date → excluded.
        $other = $this->season($club, 'archived');
        $this->entry($club, $other, self::TARGET);
        $this->em->flush();

        $rows = $this->repo->findPeriodsWithoutOverlayStartingOn($club->getId(), $season->getId(), new DateTimeImmutable(self::TARGET));
        self::assertSame([], $rows);
    }

    public function testTwoPeriodsSameDayBothReturned(): void
    {
        [$club, $season] = $this->seed('Q3');
        $this->entry($club, $season, self::TARGET);
        $this->entry($club, $season, self::TARGET);
        $this->em->flush();

        $rows = $this->repo->findPeriodsWithoutOverlayStartingOn($club->getId(), $season->getId(), new DateTimeImmutable(self::TARGET));
        self::assertCount(2, $rows);
    }

    public function testOtherClubEntryInvisible(): void
    {
        [$clubA, $seasonA] = $this->seed('Q4A');
        [$clubB, $seasonB] = $this->seed('Q4B');
        $this->entry($clubB, $seasonB, self::TARGET);
        $this->em->flush();

        $this->scopeGucToClub($clubA->getId());
        $rows = $this->repo->findPeriodsWithoutOverlayStartingOn($clubA->getId(), $seasonA->getId(), new DateTimeImmutable(self::TARGET));
        self::assertSame([], $rows);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(CalendarEntryRepository::class);
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
        if ($overlay) {
            $entry->setOverlayScheduleId('99999999-9999-4999-8999-999999999999');
        }
        $this->em->persist($entry);
        $this->em->flush();
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
