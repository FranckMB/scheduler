<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use DateTimeImmutable;

/**
 * Time footprint of a fixture on a person's availability timeline (spec
 * gestion-matchs, décision empreinte-temps). This is the atom the conflict
 * engine (PR-2) overlaps across coaches/players.
 *
 * Durations (minutes):
 * - warm-up before kickoff: 30
 * - match itself: 105 (1h45)
 * - away only: shower 30 + buffer 15 (changing) + round-trip travel
 *
 * The travel leg needs the travel matrix (palier B); here it is an injected
 * parameter (0 by default), so away footprints are computed with the fixed
 * parts now and gain the travel span when the matrix lands.
 */
final class MatchFootprint
{
    public const WARMUP_MINUTES = 30;
    public const MATCH_MINUTES = 105;
    public const AWAY_SHOWER_MINUTES = 30;
    public const AWAY_BUFFER_MINUTES = 15;

    /**
     * The [start, end] window a person is occupied by this fixture, or null
     * when the kickoff is unknown (an unplaced home fixture / an away fixture
     * with no estimated time yet).
     *
     * @param int $roundTripTravelMinutes total there-and-back travel (away only); 0 until the travel matrix exists
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    public function occupancy(Fixture $fixture, int $roundTripTravelMinutes = 0): ?array
    {
        $kickoff = $this->kickoffMoment($fixture);
        if (null === $kickoff) {
            return null;
        }

        // Half the round-trip is the outbound leg (before warm-up), the rest the
        // return leg (after shower + buffer). Home = no travel. See minutesBefore/After.
        return [
            'start' => $kickoff->modify(\sprintf('-%d minutes', $this->minutesBefore($fixture, $roundTripTravelMinutes))),
            'end' => $kickoff->modify(\sprintf('+%d minutes', $this->minutesAfter($fixture, $roundTripTravelMinutes))),
        ];
    }

    /**
     * Total NOMINAL occupied minutes (before + after the kickoff), or null when
     * the kickoff is unknown. Computed from the fixed constants, NOT from a
     * timestamp delta — a footprint spanning a DST transition must still report
     * its wall-clock duration, not the ±60 min the offset shift would add.
     */
    public function occupancyMinutes(Fixture $fixture, int $roundTripTravelMinutes = 0): ?int
    {
        if (null === $this->kickoffMoment($fixture)) {
            return null;
        }

        return $this->minutesBefore($fixture, $roundTripTravelMinutes) + $this->minutesAfter($fixture, $roundTripTravelMinutes);
    }

    private function minutesBefore(Fixture $fixture, int $roundTripTravelMinutes): int
    {
        $travelOut = FixtureHomeAway::AWAY === $fixture->getHomeAway() ? intdiv($roundTripTravelMinutes, 2) : 0;

        return $travelOut + self::WARMUP_MINUTES;
    }

    private function minutesAfter(Fixture $fixture, int $roundTripTravelMinutes): int
    {
        $isAway = FixtureHomeAway::AWAY === $fixture->getHomeAway();
        $travelBack = $isAway ? $roundTripTravelMinutes - intdiv($roundTripTravelMinutes, 2) : 0;

        return self::MATCH_MINUTES + ($isAway ? self::AWAY_SHOWER_MINUTES + self::AWAY_BUFFER_MINUTES : 0) + $travelBack;
    }

    /** The kickoff as a full moment (match date + kickoff time), or null if not set. */
    private function kickoffMoment(Fixture $fixture): ?DateTimeImmutable
    {
        $time = $fixture->getKickoffTime();
        if (null === $time) {
            return null;
        }

        return $fixture->getMatchDate()->setTime(
            (int) $time->format('H'),
            (int) $time->format('i'),
        );
    }
}
