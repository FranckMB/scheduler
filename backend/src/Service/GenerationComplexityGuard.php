<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Repository\VenueTrainingSlotRepository;

/**
 * A10 (DoS "generation bomb" guard): before a generation is queued, bound the size of
 * the club/season problem so a mid-size-but-explosive payload can never monopolise the
 * club's single generation slot for the whole solver timeout. Caps are generous (~10x a
 * large FFBB club) — they trip only on a genuine bomb. Mirrors the engine input-schema
 * max_length bounds; this pre-check rejects synchronously, before dispatch, so a bomb
 * never reaches Messenger/the engine.
 */
final class GenerationComplexityGuard
{
    public const MAX_TEAMS = 200;
    public const MAX_VENUES = 50;
    public const MAX_AVAILABILITY_SLOTS = 1000;
    public const MAX_CONSTRAINTS = 500;
    public const MAX_TEAM_VENUE_PRODUCT = 2000;

    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly VenueRepository $venueRepository,
        private readonly VenueTrainingSlotRepository $venueTrainingSlotRepository,
        private readonly ConstraintRepository $constraintRepository,
    ) {}

    /**
     * Pure cap evaluation over the counted dimensions — the first breached cap, or null.
     *
     * @return array{cap: string, count: int, limit: int}|null
     */
    public static function evaluate(int $teams, int $venues, int $slots, int $constraints): ?array
    {
        if ($teams > self::MAX_TEAMS) {
            return ['cap' => 'teams', 'count' => $teams, 'limit' => self::MAX_TEAMS];
        }
        if ($venues > self::MAX_VENUES) {
            return ['cap' => 'venues', 'count' => $venues, 'limit' => self::MAX_VENUES];
        }
        if ($slots > self::MAX_AVAILABILITY_SLOTS) {
            return ['cap' => 'availability_slots', 'count' => $slots, 'limit' => self::MAX_AVAILABILITY_SLOTS];
        }
        if ($constraints > self::MAX_CONSTRAINTS) {
            return ['cap' => 'constraints', 'count' => $constraints, 'limit' => self::MAX_CONSTRAINTS];
        }

        // The dominant driver of CP-SAT model size: every team can be placed in every venue.
        $product = $teams * $venues;
        if ($product > self::MAX_TEAM_VENUE_PRODUCT) {
            return ['cap' => 'teams_x_venues', 'count' => $product, 'limit' => self::MAX_TEAM_VENUE_PRODUCT];
        }

        return null;
    }

    /**
     * @return array{cap: string, count: int, limit: int}|null null when within limits;
     *                                                         otherwise the first breached cap
     */
    public function firstViolation(string $clubId, string $seasonId): ?array
    {
        $scope = ['clubId' => $clubId, 'seasonId' => $seasonId];

        return self::evaluate(
            $this->teamRepository->count($scope),
            $this->venueRepository->count($scope),
            $this->venueTrainingSlotRepository->count($scope),
            $this->constraintRepository->count($scope),
        );
    }
}
