<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Enum\FixtureHomeAway;
use App\Enum\TeamCoachRole;
use App\Service\MatchConflictDetector;
use App\Service\MatchFootprint;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Same-coach conflict detection (spec gestion-matchs PR-2): match↔match,
 * match↔training against the schedule effective on the match date, half-open
 * overlap, away-without-kickoff ignored.
 */
#[Group('unit')]
final class MatchConflictDetectorTest extends TestCase
{
    private const COACH_A = 'coach-a';
    private const COACH_B = 'coach-b';
    private const TEAM_1 = 'team-1';
    private const TEAM_2 = 'team-2';
    private const BASELINE = 'sched-baseline';
    private const OVERLAY = 'sched-overlay';

    public function testTwoMatchesOfSameCoachOverlappingRaiseOneConflict(): void
    {
        // Coach A coaches team-1 AND team-2; both play overlapping windows.
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'), // 15:30–17:45
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30'), // 16:00–18:15
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $conflicts = $this->detect($fixtures, $links);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame(self::COACH_A, $conflicts[0]['coachId']);
    }

    public function testDisjointMatchesRaiseNoConflict(): void
    {
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '10:00'), // 09:30–11:45
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:00'), // 15:30–17:45
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testBackToBackWindowsDoNotConflict(): void
    {
        // fx-1 ends 17:45 ; fx-2 (away, no travel) starts 17:45 → half-open, no clash.
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'), // home 15:30–17:45
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '18:15'), // home 17:45–20:00
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testAwayFixtureWithoutKickoffIsIgnored(): void
    {
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'),
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', null), // no footprint
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testDifferentCoachesNeverConflict(): void
    {
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'),
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30'), // overlapping windows
        ];
        // team-1 → coach A, team-2 → coach B: no shared coach.
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_B, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testMatchOverlappingBaselineTrainingSameWeekdayConflicts(): void
    {
        // 2026-10-04 is a Sunday (ISO 7). Coach A's team-1 trains Sunday 17:00–18:30,
        // the match runs 15:30–17:45 → overlap.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-1', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testTrainingOnDifferentWeekdayDoesNotConflict(): void
    {
        // Slot on Monday (1) but the match is Sunday → projection excludes it.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 1, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]));
    }

    public function testTrainingOfAnotherCoachesTeamDoesNotConflict(): void
    {
        // The overlapping Sunday slot belongs to team-2, which coach A does NOT coach.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]));
    }

    public function testOverlayPlanReplacesBaselineOnCoveredDate(): void
    {
        // The match date falls in an active period with an overlay: the overlay
        // slot (overlapping) drives the conflict, the baseline slot is ignored.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $overlayPeriods = [[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => self::OVERLAY,
        ]];
        $slotsBySchedule = [
            self::BASELINE => [$this->slot('base-sl', self::BASELINE, self::TEAM_1, 7, '17:00', 90)],
            self::OVERLAY => [$this->slot('ovl-sl', self::OVERLAY, self::TEAM_1, 7, '17:00', 90)],
        ];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, $overlayPeriods, $slotsBySchedule);

        self::assertCount(1, $conflicts);
        self::assertSame('ovl-sl', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testNoBaselineYieldsNoTrainingConflict(): void
    {
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        // No baseline scheduleId → nothing to resolve → no training conflict.
        self::assertSame([], $this->detect($fixtures, $links, null, [], [self::BASELINE => $slots]));
    }

    public function testActivePeriodWithoutOverlaySuspendsBaselineTraining(): void
    {
        // A closure/holiday recorded as an active period with NO overlay (training
        // suspended, plan not regenerated) captures the date → the baseline slot is
        // NOT checked, so no phantom conflict against a cancelled training.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $activePeriods = [[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => null, // period active but no overlay generated
        ]];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, $activePeriods, [self::BASELINE => $slots]));
    }

    public function testFootprintCrossingMidnightChecksNextDaySlots(): void
    {
        // Home match 23:00 on Sunday → footprint 22:30–00:45 (Monday). A Monday
        // 00:00–01:00 training of the coach's team overlaps past midnight and must
        // be caught even though the match date's weekday is Sunday.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '23:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-mon', self::BASELINE, self::TEAM_1, 1, '00:00', 60)]; // Monday 00:00–01:00

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-mon', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testAssignedSlotCoachDoesNotFlagCoCoaches(): void
    {
        // Team-1 has two coaches A and B; the overlapping Sunday slot is assigned to
        // A only. Only A is double-booked — B (who does not run this slot) must not
        // be flagged.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_B, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90, self::COACH_A)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame(self::COACH_A, $conflicts[0]['coachId']);
    }

    /**
     * @param list<Fixture>                                                                     $fixtures
     * @param list<TeamCoach>                                                                   $links
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string}> $overlayPeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                         $slotsBySchedule
     *
     * @return list<array<string, mixed>>
     */
    private function detect(array $fixtures, array $links, ?string $baselineScheduleId = null, array $overlayPeriods = [], array $slotsBySchedule = []): array
    {
        return new MatchConflictDetector(new MatchFootprint)
            ->detect($fixtures, $links, $baselineScheduleId, $overlayPeriods, $slotsBySchedule);
    }

    private function fixture(string $id, string $teamId, string $date, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        $this->setId($fixture, $id);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(null === $kickoff ? null : (DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null));

        return $fixture;
    }

    private function link(string $coachId, string $teamId): TeamCoach
    {
        $link = new TeamCoach;
        $link->setClubId('club');
        $link->setSeasonId('season');
        $link->setTeamId($teamId);
        $link->setCoachId($coachId);
        $link->setRole(TeamCoachRole::MAIN);

        return $link;
    }

    private function slot(string $id, string $scheduleId, string $teamId, int $dayOfWeek, string $start, int $durationMinutes, ?string $coachId = null): ScheduleSlotTemplate
    {
        $slot = new ScheduleSlotTemplate;
        $this->setId($slot, $id);
        $slot->setScheduleId($scheduleId);
        $slot->setTeamId($teamId);
        $slot->setVenueId('venue');
        $slot->setCoachId($coachId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $start) ?: new DateTimeImmutable('00:00'));
        $slot->setDurationMinutes($durationMinutes);

        return $slot;
    }

    /** Ids are DB-generated (no setter) — set the private field for pure-unit assertions. */
    private function setId(object $entity, string $id): void
    {
        $ref = new ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
