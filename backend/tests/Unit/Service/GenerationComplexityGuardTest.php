<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GenerationComplexityGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A10: the complexity guard bounds the club/season problem before a generation is
 * queued. Each cap is checked in isolation over the pure evaluation (no DB seeding).
 * Signature: evaluate(teams, venues, coaches, slots, constraints).
 */
#[Group('phase1')]
final class GenerationComplexityGuardTest extends TestCase
{
    public function testWithinAllLimitsReturnsNull(): void
    {
        self::assertNull(GenerationComplexityGuard::evaluate(40, 8, 30, 300, 50));
    }

    public function testTeamsCapTripsFirst(): void
    {
        self::assertSame(
            ['cap' => 'teams', 'count' => 201, 'limit' => 200],
            GenerationComplexityGuard::evaluate(201, 8, 0, 0, 0),
        );
    }

    public function testVenuesCap(): void
    {
        self::assertSame(
            ['cap' => 'venues', 'count' => 51, 'limit' => 50],
            GenerationComplexityGuard::evaluate(10, 51, 0, 0, 0),
        );
    }

    public function testCoachesCap(): void
    {
        self::assertSame(
            ['cap' => 'coaches', 'count' => 201, 'limit' => 200],
            GenerationComplexityGuard::evaluate(10, 10, 201, 0, 0),
        );
    }

    public function testAvailabilitySlotsCap(): void
    {
        self::assertSame(
            ['cap' => 'availability_slots', 'count' => 3001, 'limit' => 3000],
            GenerationComplexityGuard::evaluate(10, 10, 0, 3001, 0),
        );
    }

    public function testConstraintsCap(): void
    {
        self::assertSame(
            ['cap' => 'constraints', 'count' => 501, 'limit' => 500],
            GenerationComplexityGuard::evaluate(10, 10, 0, 100, 501),
        );
    }

    public function testTeamVenueProductCapTripsEvenWhenEachDimensionIsUnderItsOwnCap(): void
    {
        // 45 x 45 = 2025 > 2000, yet 45 < 200 teams and 45 < 50 venues.
        self::assertSame(
            ['cap' => 'teams_x_venues', 'count' => 2025, 'limit' => 2000],
            GenerationComplexityGuard::evaluate(45, 45, 0, 0, 0),
        );
    }
}
