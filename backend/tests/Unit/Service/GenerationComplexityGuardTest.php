<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GenerationComplexityGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A10: the complexity guard bounds the club/season problem before a generation is
 * queued. Each cap is checked in isolation over the pure evaluation (no DB seeding).
 */
#[Group('phase1')]
final class GenerationComplexityGuardTest extends TestCase
{
    public function testWithinAllLimitsReturnsNull(): void
    {
        self::assertNull(GenerationComplexityGuard::evaluate(teams: 40, venues: 8, slots: 300, constraints: 50));
    }

    public function testTeamsCapTripsFirst(): void
    {
        self::assertSame(
            ['cap' => 'teams', 'count' => 201, 'limit' => 200],
            GenerationComplexityGuard::evaluate(teams: 201, venues: 8, slots: 0, constraints: 0),
        );
    }

    public function testVenuesCap(): void
    {
        self::assertSame(
            ['cap' => 'venues', 'count' => 51, 'limit' => 50],
            GenerationComplexityGuard::evaluate(teams: 10, venues: 51, slots: 0, constraints: 0),
        );
    }

    public function testAvailabilitySlotsCap(): void
    {
        self::assertSame(
            ['cap' => 'availability_slots', 'count' => 1001, 'limit' => 1000],
            GenerationComplexityGuard::evaluate(teams: 10, venues: 10, slots: 1001, constraints: 0),
        );
    }

    public function testConstraintsCap(): void
    {
        self::assertSame(
            ['cap' => 'constraints', 'count' => 501, 'limit' => 500],
            GenerationComplexityGuard::evaluate(teams: 10, venues: 10, slots: 100, constraints: 501),
        );
    }

    public function testTeamVenueProductCapTripsEvenWhenEachDimensionIsUnderItsOwnCap(): void
    {
        // 45 x 45 = 2025 > 2000, yet 45 < 200 teams and 45 < 50 venues.
        self::assertSame(
            ['cap' => 'teams_x_venues', 'count' => 2025, 'limit' => 2000],
            GenerationComplexityGuard::evaluate(teams: 45, venues: 45, slots: 0, constraints: 0),
        );
    }
}
