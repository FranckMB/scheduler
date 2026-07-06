<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Season;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SeasonResolverTest extends TestCase
{
    public function testSeasonYearPivotsOnJulyFifteen(): void
    {
        self::assertSame(2025, SeasonResolver::seasonYear(new DateTimeImmutable('2026-07-14')));
        self::assertSame(2026, SeasonResolver::seasonYear(new DateTimeImmutable('2026-07-15')));
        self::assertSame(2026, SeasonResolver::seasonYear(new DateTimeImmutable('2026-07-16')));
        self::assertSame(2025, SeasonResolver::seasonYear(new DateTimeImmutable('2026-01-10')));
        self::assertSame(2026, SeasonResolver::seasonYear(new DateTimeImmutable('2026-12-31')));
    }

    public function testCurrentSwitchesToNextSeasonOnPivotDay(): void
    {
        $n = $this->season('2025-08-01', '2026-07-15');
        $n1 = $this->season('2026-08-01', '2027-07-15');
        $seasons = [$n, $n1];

        self::assertSame($n, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-07-14')));
        self::assertSame($n1, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-07-15')));
        self::assertSame($n1, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-07-16')));
    }

    public function testGapBetweenSeasonsStaysOnPreviousUntilPivot(): void
    {
        // Fixture-style dates: sept → june, today in the gap before the pivot.
        $n = $this->season('2025-09-01', '2026-06-30');
        $n1 = $this->season('2026-09-01', '2027-06-30');

        self::assertSame($n, SeasonResolver::currentAmong([$n, $n1], new DateTimeImmutable('2026-07-01')));
        self::assertSame($n1, SeasonResolver::currentAmong([$n, $n1], new DateTimeImmutable('2026-07-20')));
    }

    public function testMonoSeasonClubIsCurrentUnconditionally(): void
    {
        // Zero-behaviour-change guarantee — whatever the dates, even absurd ones.
        $future = $this->season('2099-08-01', '2100-07-15');

        self::assertSame($future, SeasonResolver::currentAmong([$future], new DateTimeImmutable('2026-07-06')));
    }

    public function testRegisterSeedBugDatesAreTolerated(): void
    {
        // Historical register seed wrote endDate < startDate; the rule keys on
        // startDate only so the derivation must not care.
        $buggy = $this->season('2025-08-01', '2025-07-15');
        $next = $this->season('2026-08-01', '2026-07-15');

        self::assertSame($buggy, SeasonResolver::currentAmong([$buggy, $next], new DateTimeImmutable('2026-05-01')));
        self::assertSame($next, SeasonResolver::currentAmong([$buggy, $next], new DateTimeImmutable('2026-08-20')));
    }

    public function testEarlyStartNextSeasonWaitsForItsActualStartDate(): void
    {
        // N+1 created with a startDate BEFORE the pivot (July 1 → same
        // season-year bin as the running season): it must NOT become current
        // before it actually starts, then must win from its startDate.
        $n = $this->season('2025-08-01', '2026-06-30');
        $early = $this->season('2026-07-01', '2027-06-30');
        $seasons = [$n, $early];

        self::assertSame($n, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-01-10')));
        self::assertSame($n, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-06-30')));
        self::assertSame($early, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-07-01')));
        self::assertSame($early, SeasonResolver::currentAmong($seasons, new DateTimeImmutable('2026-07-20')));
    }

    public function testAllFutureSeasonsFallBackToTheEarliest(): void
    {
        $a = $this->season('2098-08-01', '2099-07-15');
        $b = $this->season('2099-08-01', '2100-07-15');

        self::assertSame($a, SeasonResolver::currentAmong([$a, $b], new DateTimeImmutable('2026-07-06')));
    }

    public function testIsReadonlyOnlyForPastSeasons(): void
    {
        $past = $this->season('2024-08-01', '2025-07-15');
        $current = $this->season('2025-08-01', '2026-07-15');
        $draft = $this->season('2026-08-01', '2027-07-15');
        $seasons = [$past, $current, $draft];
        $today = new DateTimeImmutable('2026-07-06');

        self::assertTrue(SeasonResolver::isReadonlyAmong($past, $seasons, $today));
        self::assertFalse(SeasonResolver::isReadonlyAmong($current, $seasons, $today));
        self::assertFalse(SeasonResolver::isReadonlyAmong($draft, $seasons, $today));
    }

    private function season(string $start, string $end): Season
    {
        $season = new Season;
        $season->setClubId('club-1');
        $season->setName('saison');
        $season->setStartDate(new DateTimeImmutable($start));
        $season->setEndDate(new DateTimeImmutable($end));
        $season->setStatus('active');
        $season->setTransitionData([]);

        return $season;
    }
}
