<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ImplicitConstraintConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ImplicitConstraintConfigTest extends TestCase
{
    private ImplicitConstraintConfig $config;

    public function testGetConfigReturnsFiveImplicitConstraints(): void
    {
        $result = $this->config->getConfig();

        self::assertCount(5, $result);
        self::assertArrayHasKey('venueAtMostOne', $result);
        self::assertArrayHasKey('coachNoOverlap', $result);
        self::assertArrayHasKey('coachPlayerNoOverlap', $result);
        self::assertArrayHasKey('teamNoOverlap', $result);
        self::assertArrayHasKey('minSessions', $result);
    }

    public function testVenueAtMostOneConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('VENUE_AT_MOST_ONE', $result['venueAtMostOne']['type']);
        self::assertTrue($result['venueAtMostOne']['enabled']);
        self::assertSame('One venue hosts max one team per time slot', $result['venueAtMostOne']['description']);
    }

    public function testCoachNoOverlapConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('COACH_NO_OVERLAP', $result['coachNoOverlap']['type']);
        self::assertTrue($result['coachNoOverlap']['enabled']);
        self::assertSame('One coach coaches max one team per time slot', $result['coachNoOverlap']['description']);
    }

    public function testCoachPlayerNoOverlapConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('COACH_PLAYER_NO_OVERLAP', $result['coachPlayerNoOverlap']['type']);
        self::assertTrue($result['coachPlayerNoOverlap']['enabled']);
        self::assertSame('A coach-player cannot be in two roles simultaneously', $result['coachPlayerNoOverlap']['description']);
    }

    public function testTeamNoOverlapConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('TEAM_NO_OVERLAP', $result['teamNoOverlap']['type']);
        self::assertTrue($result['teamNoOverlap']['enabled']);
        self::assertSame('A team cannot have two sessions at the same time', $result['teamNoOverlap']['description']);
    }

    public function testMinSessionsConfig(): void
    {
        $result = $this->config->getConfig();

        self::assertSame('MIN_SESSIONS', $result['minSessions']['type']);
        self::assertTrue($result['minSessions']['enabled']);
        self::assertSame('Each team gets at least its effective minimum sessions', $result['minSessions']['description']);
    }

    public function testGetConstraintsArrayReturnsFiveIndexedEntries(): void
    {
        $result = $this->config->getConstraintsArray();

        self::assertCount(5, $result);
        self::assertSame('VENUE_AT_MOST_ONE', $result[0]['type']);
    }

    protected function setUp(): void
    {
        $this->config = new ImplicitConstraintConfig;
    }
}
