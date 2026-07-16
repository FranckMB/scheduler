<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use DateInterval;
use DateTimeImmutable;

/**
 * Detects, on the fly, the time-occupancy conflicts a SINGLE coach faces in a
 * season (spec gestion-matchs palier A, PR-2). In an amateur club a match and a
 * training can NEVER overlap for the same person — this surfaces the clash as
 * early as the fixture is entered (the anticipation value of the module).
 *
 * Two conflict kinds, both built on {@see MatchFootprint} occupancy windows:
 * - MATCH_MATCH: two fixtures of teams sharing a coach whose windows overlap.
 * - MATCH_TRAINING: a fixture overlapping a training of one of the coach's teams,
 *   read from the schedule EFFECTIVE on the match date. An active period entry
 *   CAPTURES the dates it covers — inside it the base plan does not apply: its
 *   overlay if any, else no training plan at all (a closure means "no training").
 *   Outside any period the season baseline applies. A footprint crossing midnight
 *   is checked against BOTH calendar days it spans.
 *
 * Pure/stateless: the controller loads the scoped data and passes it in; this
 * class only crosses and overlaps, so it is unit-testable without a kernel.
 *
 * Away travel is not modelled yet (palier B): an away fixture with no estimated
 * kickoff has no footprint and therefore raises no conflict — intended.
 */
final class MatchConflictDetector
{
    public function __construct(private readonly MatchFootprint $footprint) {}

    /**
     * @param list<Fixture>                                                                          $fixtures         season fixtures (already club+season scoped)
     * @param list<TeamCoach>                                                                        $teamCoachRows    coach↔team links (scoped)
     * @param string|null                                                                            $seasonScheduleId the season's calendar (the version its plan points at), or null
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     *                                                                                                                 active period windows (ordered), scheduleId = their overlay or null
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule  slots indexed by their scheduleId
     *
     * @return list<array<string, mixed>> conflict items ready to serialize
     */
    public function detect(
        array $fixtures,
        array $teamCoachRows,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
    ): array {
        $coachesByTeam = [];
        foreach ($teamCoachRows as $link) {
            $coachesByTeam[$link->getTeamId()][$link->getCoachId()] = true;
        }

        // Placed fixtures = those with a footprint (kickoff known). Attach the
        // coaches of the fixture's team; a fixture whose team has no coach cannot
        // clash with anyone, so it is dropped here.
        $views = [];
        foreach ($fixtures as $fixture) {
            $window = $this->footprint->occupancy($fixture);
            if (null === $window) {
                continue;
            }
            $coachIds = array_keys($coachesByTeam[$fixture->getTeamId()] ?? []);
            if ([] === $coachIds) {
                continue;
            }
            $views[] = ['fixture' => $fixture, 'window' => $window, 'coachIds' => $coachIds];
        }

        return [
            ...$this->matchMatchConflicts($views),
            ...$this->matchTrainingConflicts($views, $coachesByTeam, $seasonScheduleId, $activePeriods, $slotsBySchedule),
        ];
    }

    /**
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, coachIds: list<string>}> $views
     *
     * @return list<array<string, mixed>>
     */
    private function matchMatchConflicts(array $views): array
    {
        $conflicts = [];
        $count = \count($views);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $left = $views[$i];
                $right = $views[$j];
                if (!$this->overlaps($left['window'], $right['window'])) {
                    continue;
                }
                // A coach shared by both fixtures' teams is double-booked.
                foreach (array_intersect($left['coachIds'], $right['coachIds']) as $coachId) {
                    $conflicts[] = [
                        'type' => 'MATCH_MATCH',
                        'coachId' => $coachId,
                        'start' => $this->maxMoment($left['window']['start'], $right['window']['start'])->format(DateTimeImmutable::ATOM),
                        'end' => $this->minMoment($left['window']['end'], $right['window']['end'])->format(DateTimeImmutable::ATOM),
                        'left' => $this->fixtureView($left['fixture'], $left['window']),
                        'right' => $this->fixtureView($right['fixture'], $right['window']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, coachIds: list<string>}> $views
     * @param array<string, array<string, true>>                                                                                     $coachesByTeam
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>                                 $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                                                              $slotsBySchedule
     *
     * @return list<array<string, mixed>>
     */
    private function matchTrainingConflicts(
        array $views,
        array $coachesByTeam,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
    ): array {
        $conflicts = [];
        foreach ($views as $view) {
            // A footprint can cross midnight (late kickoff) — check every calendar
            // day it spans, each resolving its own effective schedule + weekday.
            foreach ($this->spannedDates($view['window']) as $date) {
                $scheduleId = $this->effectiveScheduleId($date, $activePeriods, $seasonScheduleId);
                if (null === $scheduleId) {
                    continue;
                }
                $isoWeekday = (int) $date->format('N');

                foreach ($slotsBySchedule[$scheduleId] ?? [] as $slot) {
                    if ($slot->getDayOfWeek() !== $isoWeekday) {
                        continue;
                    }
                    // Who actually runs the training: the slot's assigned coach if
                    // any, else any coach of the slot's team. Intersect with this
                    // fixture's coaches — only a coach on BOTH sides is double-booked.
                    $slotCoach = $slot->getCoachId();
                    $trainingCoachIds = null !== $slotCoach
                        ? [$slotCoach]
                        : array_keys($coachesByTeam[$slot->getTeamId()] ?? []);
                    $coachIds = array_values(array_intersect($view['coachIds'], $trainingCoachIds));
                    if ([] === $coachIds) {
                        continue;
                    }

                    $trainingWindow = $this->slotWindowOnDate($date, $slot);
                    if (!$this->overlaps($view['window'], $trainingWindow)) {
                        continue;
                    }

                    foreach ($coachIds as $coachId) {
                        $conflicts[] = [
                            'type' => 'MATCH_TRAINING',
                            'coachId' => $coachId,
                            'start' => $this->maxMoment($view['window']['start'], $trainingWindow['start'])->format(DateTimeImmutable::ATOM),
                            'end' => $this->minMoment($view['window']['end'], $trainingWindow['end'])->format(DateTimeImmutable::ATOM),
                            'fixture' => $this->fixtureView($view['fixture'], $view['window']),
                            'training' => [
                                'slotTemplateId' => $slot->getId(),
                                'scheduleId' => $slot->getScheduleId(),
                                'teamId' => $slot->getTeamId(),
                                'venueId' => $slot->getVenueId(),
                                'dayOfWeek' => $slot->getDayOfWeek(),
                                'startTime' => $slot->getStartTime()->format('H:i'),
                                'durationMinutes' => $slot->getDurationMinutes(),
                                'windowStart' => $trainingWindow['start']->format(DateTimeImmutable::ATOM),
                                'windowEnd' => $trainingWindow['end']->format(DateTimeImmutable::ATOM),
                            ],
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * The calendar days a window touches — its start day, plus its end day when
     * the window crosses midnight (footprints are short, so at most two days).
     *
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $window
     *
     * @return list<DateTimeImmutable> midnight of each spanned day
     */
    private function spannedDates(array $window): array
    {
        $startDay = $window['start']->setTime(0, 0);
        $endDay = $window['end']->setTime(0, 0);
        if ($startDay->format('Y-m-d') === $endDay->format('Y-m-d')) {
            return [$startDay];
        }

        return [$startDay, $endDay];
    }

    /**
     * The schedule effective on a date. An active period covering the date
     * captures it — its overlay (may be null → no training plan) wins; the first
     * covering period in the ordered list decides. Outside any period the season's
     * own calendar applies.
     *
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     */
    private function effectiveScheduleId(DateTimeImmutable $date, array $activePeriods, ?string $seasonScheduleId): ?string
    {
        foreach ($activePeriods as $period) {
            if ($date >= $period['start'] && $date <= $period['end']) {
                return $period['scheduleId'];
            }
        }

        return $seasonScheduleId;
    }

    /**
     * Project a weekly recurring slot onto a concrete date (the caller has
     * already matched the ISO weekday).
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    private function slotWindowOnDate(DateTimeImmutable $date, ScheduleSlotTemplate $slot): array
    {
        $slotStart = $slot->getStartTime();
        $start = $date->setTime((int) $slotStart->format('H'), (int) $slotStart->format('i'));

        return ['start' => $start, 'end' => $start->add(new DateInterval('PT' . $slot->getDurationMinutes() . 'M'))];
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $a
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $b
     */
    private function overlaps(array $a, array $b): bool
    {
        // Half-open: back-to-back windows (endA == startB) do NOT conflict.
        return $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    private function maxMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private function minMoment(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    /**
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $window
     *
     * @return array<string, mixed>
     */
    private function fixtureView(Fixture $fixture, array $window): array
    {
        return [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            'windowStart' => $window['start']->format(DateTimeImmutable::ATOM),
            'windowEnd' => $window['end']->format(DateTimeImmutable::ATOM),
        ];
    }
}
