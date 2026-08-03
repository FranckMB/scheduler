<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueUnavailability;
use App\Enum\FixtureHomeAway;
use App\Enum\TeamLinkType;
use DateInterval;
use DateTimeImmutable;

/**
 * Detects, on the fly, the time-occupancy conflicts a SINGLE coach faces in a
 * season (spec gestion-matchs palier A, PR-2). In an amateur club a match and a
 * training can NEVER overlap for the same person — this surfaces the clash as
 * early as the fixture is entered (the anticipation value of the module).
 *
 * Three conflict kinds. The first two are built on {@see MatchFootprint}
 * occupancy windows:
 * - MATCH_MATCH: two fixtures of teams sharing a coach whose windows overlap.
 * - MATCH_TRAINING: a fixture overlapping a training of one of the coach's teams,
 *   read from the schedule EFFECTIVE on the match date (rule extracted to
 *   {@see EffectiveScheduleResolver} — P1-4 PR B). A footprint crossing midnight
 *   is checked against BOTH calendar days it spans.
 * - VENUE_UNAVAILABLE (P1-4 PR B): a fixture whose venue is unavailable on its
 *   date (all-circumstances closure posed on the club calendar AFTER the match
 *   was placed — the real-life case the placement guard cannot catch). Coach-
 *   independent, kickoff-independent: the DATE falling in the range suffices.
 * - TEAM_LINK_OVERLAP (P1-4 PR C): two fixtures of two DECLARED-linked teams
 *   (« SM1 et SM2 partagent des joueurs ») whose footprints overlap. Only
 *   NOT_SIMULTANEOUS links raise it — BACK_TO_BACK is a preference the PR D
 *   solver consumes, not an objective rule.
 *
 * Away-kickoff ESTIMATION (P1-4 PR C, resorbs the PR-2 blind spot): an AWAY
 * fixture without a real hour borrows its team's HABITUAL kickoff when a habit
 * exists on the match's weekday — the footprint is born, conflicts become
 * visible, flagged `estimatedKickoff: true`. Nothing is persisted. No habit
 * on that weekday → still no footprint (told apart in PR E's graded
 * diagnostic). Unplaced HOME fixtures are NOT estimated: their hour is the
 * manager's next gesture, estimating it would be noise.
 *
 * Pure/stateless: the controller loads the scoped data and passes it in; this
 * class only crosses and overlaps, so it is unit-testable without a kernel.
 *
 * Away travel is not modelled yet (palier B): an away fixture with no estimated
 * kickoff has no footprint and therefore raises no conflict — intended.
 */
final class MatchConflictDetector
{
    public function __construct(
        private readonly MatchFootprint $footprint,
        private readonly EffectiveScheduleResolver $effectiveScheduleResolver,
    ) {}

    /**
     * @param list<Fixture>                                                                          $fixtures         season fixtures (already club+season scoped)
     * @param list<TeamCoach>                                                                        $teamCoachRows    coach↔team links (scoped)
     * @param string|null                                                                            $seasonScheduleId the season's calendar (the version its plan points at), or null
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $activePeriods
     *                                                                                                                 active period windows (ordered), scheduleId = their overlay or null
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule  slots indexed by their scheduleId
     * @param list<VenueUnavailability>                                                              $unavailabilities scoped all-circumstances closures
     * @param list<TeamMatchHabit>                                                                   $habits           scoped habitual windows (estimation source)
     * @param list<TeamLink>                                                                         $teamLinks        scoped declared bridges
     *
     * @return list<array<string, mixed>> conflict items ready to serialize
     */
    public function detect(
        array $fixtures,
        array $teamCoachRows,
        ?string $seasonScheduleId,
        array $activePeriods,
        array $slotsBySchedule,
        array $unavailabilities = [],
        array $habits = [],
        array $teamLinks = [],
    ): array {
        $coachesByTeam = [];
        foreach ($teamCoachRows as $link) {
            $coachesByTeam[$link->getTeamId()][$link->getCoachId()] = true;
        }

        /** @var array<string, array<int, TeamMatchHabit>> $habitByTeamDay */
        $habitByTeamDay = [];
        foreach ($habits as $habit) {
            $habitByTeamDay[$habit->getTeamId()][$habit->getDayOfWeek()] = $habit;
        }

        // Fixtures with a footprint: a real kickoff, or (P1-4 PR C) an AWAY
        // fixture borrowing its team's habitual kickoff for the match weekday.
        // Coaches attached for the coach conflicts; a coach-less fixture still
        // gets a view (team links don't need a coach).
        $views = [];
        foreach ($fixtures as $fixture) {
            $estimated = false;
            $window = $this->footprint->occupancy($fixture);
            if (null === $window
                && FixtureHomeAway::AWAY === $fixture->getHomeAway()
                && null === $fixture->getKickoffTime()
            ) {
                $habit = $habitByTeamDay[$fixture->getTeamId()][(int) $fixture->getMatchDate()->format('N')] ?? null;
                if ($habit instanceof TeamMatchHabit) {
                    $window = $this->footprint->occupancyAt($fixture, $habit->getKickoffTime());
                    $estimated = true;
                }
            }
            if (null === $window) {
                continue;
            }
            $views[] = [
                'fixture' => $fixture,
                'window' => $window,
                'estimated' => $estimated,
                'coachIds' => array_keys($coachesByTeam[$fixture->getTeamId()] ?? []),
            ];
        }
        $coachViews = array_values(array_filter($views, static fn (array $view): bool => [] !== $view['coachIds']));

        return [
            ...$this->matchMatchConflicts($coachViews),
            ...$this->matchTrainingConflicts($coachViews, $coachesByTeam, $seasonScheduleId, $activePeriods, $slotsBySchedule),
            ...$this->venueUnavailableConflicts($fixtures, $unavailabilities),
            ...$this->teamLinkConflicts($views, $teamLinks),
        ];
    }

    /**
     * Two fixtures of two DECLARED-linked teams overlapping (NOT_SIMULTANEOUS
     * only — BACK_TO_BACK is a solver preference, diagnosing its non-respect
     * without a solver would invent a rule). Coach-independent.
     *
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
     * @param list<TeamLink>                                                                                                                          $teamLinks
     *
     * @return list<array<string, mixed>>
     */
    private function teamLinkConflicts(array $views, array $teamLinks): array
    {
        if ([] === $teamLinks) {
            return [];
        }

        /** @var array<string, list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool}>> $byTeam */
        $byTeam = [];
        foreach ($views as $view) {
            $byTeam[$view['fixture']->getTeamId()][] = $view;
        }

        $conflicts = [];
        foreach ($teamLinks as $link) {
            if (TeamLinkType::NOT_SIMULTANEOUS !== $link->getLinkType()) {
                continue;
            }
            foreach ($byTeam[$link->getTeamAId()] ?? [] as $left) {
                foreach ($byTeam[$link->getTeamBId()] ?? [] as $right) {
                    if (!$this->overlaps($left['window'], $right['window'])) {
                        continue;
                    }
                    $conflicts[] = [
                        'type' => 'TEAM_LINK_OVERLAP',
                        'teamLinkId' => $link->getId(),
                        'start' => $this->maxMoment($left['window']['start'], $right['window']['start'])->format(DateTimeImmutable::ATOM),
                        'end' => $this->minMoment($left['window']['end'], $right['window']['end'])->format(DateTimeImmutable::ATOM),
                        'left' => $this->fixtureView($left['fixture'], $left['window'], $left['estimated']),
                        'right' => $this->fixtureView($right['fixture'], $right['window'], $right['estimated']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * A fixture sitting on a venue that is unavailable on its date. No window
     * math: the closure is all-circumstances, the DATE match suffices (a
     * kickoff-less home fixture with a venue is affected too).
     *
     * @param list<Fixture>             $fixtures
     * @param list<VenueUnavailability> $unavailabilities
     *
     * @return list<array<string, mixed>>
     */
    private function venueUnavailableConflicts(array $fixtures, array $unavailabilities): array
    {
        if ([] === $unavailabilities) {
            return [];
        }

        $conflicts = [];
        foreach ($fixtures as $fixture) {
            $venueId = $fixture->getVenueId();
            if (null === $venueId) {
                continue;
            }
            $date = $fixture->getMatchDate()->format('Y-m-d');
            foreach ($unavailabilities as $unavailability) {
                if ($unavailability->getVenueId() !== $venueId) {
                    continue;
                }
                // Inclusive bounds: « du 4 au 28 février » covers the 28th.
                if ($date < $unavailability->getStartDate()->format('Y-m-d') || $date > $unavailability->getEndDate()->format('Y-m-d')) {
                    continue;
                }
                $conflicts[] = [
                    'type' => 'VENUE_UNAVAILABLE',
                    'venueId' => $venueId,
                    'unavailabilityId' => $unavailability->getId(),
                    'label' => $unavailability->getLabel(),
                    'unavailableFrom' => $unavailability->getStartDate()->format('Y-m-d'),
                    'unavailableUntil' => $unavailability->getEndDate()->format('Y-m-d'),
                    'fixture' => [
                        'fixtureId' => $fixture->getId(),
                        'teamId' => $fixture->getTeamId(),
                        'homeAway' => $fixture->getHomeAway()->value,
                        'matchDate' => $date,
                        'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
                        'status' => $fixture->getStatus()->value,
                    ],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
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
                        'left' => $this->fixtureView($left['fixture'], $left['window'], $left['estimated']),
                        'right' => $this->fixtureView($right['fixture'], $right['window'], $right['estimated']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<array{fixture: Fixture, window: array{start: DateTimeImmutable, end: DateTimeImmutable}, estimated: bool, coachIds: list<string>}> $views
     * @param array<string, array<string, true>>                                                                                                      $coachesByTeam
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}>                                                  $activePeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                                                                               $slotsBySchedule
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
                $scheduleId = $this->effectiveScheduleResolver->resolve($date, $activePeriods, $seasonScheduleId);
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
                            'fixture' => $this->fixtureView($view['fixture'], $view['window'], $view['estimated']),
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
    private function fixtureView(Fixture $fixture, array $window, bool $estimated = false): array
    {
        return [
            'fixtureId' => $fixture->getId(),
            'teamId' => $fixture->getTeamId(),
            'homeAway' => $fixture->getHomeAway()->value,
            'matchDate' => $fixture->getMatchDate()->format('Y-m-d'),
            'kickoffTime' => $fixture->getKickoffTime()?->format('H:i'),
            // P1-4 PR C — the window was built on the team's HABITUAL kickoff,
            // not a real hour: the UI must say « heure estimée ».
            'estimatedKickoff' => $estimated,
            'windowStart' => $window['start']->format(DateTimeImmutable::ATOM),
            'windowEnd' => $window['end']->format(DateTimeImmutable::ATOM),
        ];
    }
}
