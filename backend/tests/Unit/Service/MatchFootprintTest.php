<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Service\MatchFootprint;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class MatchFootprintTest extends TestCase
{
    public function testHomeFixtureIsWarmupPlusMatch(): void
    {
        // Domicile : 30 min échauffement + 1h45 match = 2h15, de kickoff-30 à kickoff+105.
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', '16:00');
        $window = new MatchFootprint()->occupancy($fixture);

        self::assertNotNull($window);
        self::assertSame('2026-10-04 15:30', $window['start']->format('Y-m-d H:i'));
        self::assertSame('2026-10-04 17:45', $window['end']->format('Y-m-d H:i'));
        self::assertSame(135, new MatchFootprint()->occupancyMinutes($fixture));
    }

    public function testAwayFixtureAddsShowerAndBuffer(): void
    {
        // Extérieur sans trajet : + 30 douche + 15 battement = 135 + 45 = 180 min.
        $fixture = $this->fixture(FixtureHomeAway::AWAY, '2026-10-04', '15:30');
        self::assertSame(180, new MatchFootprint()->occupancyMinutes($fixture));
    }

    public function testAwayFixtureAddsRoundTripTravelSplitBeforeAndAfter(): void
    {
        // 80 min aller-retour → 40 avant (aller) + 40 après (retour), en plus des 180.
        $fixture = $this->fixture(FixtureHomeAway::AWAY, '2026-10-04', '15:30');
        $window = new MatchFootprint()->occupancy($fixture, 80);

        self::assertNotNull($window);
        // start = kickoff - (travelOut 40 + warmup 30) = 15:30 - 70 = 14:20
        self::assertSame('14:20', $window['start']->format('H:i'));
        // end = kickoff + (match 105 + shower 30 + buffer 15 + travelBack 40) = 15:30 + 190 = 18:40
        self::assertSame('18:40', $window['end']->format('H:i'));
        self::assertSame(260, new MatchFootprint()->occupancyMinutes($fixture, 80));
    }

    public function testNoKickoffYieldsNoFootprint(): void
    {
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', null);
        self::assertNull(new MatchFootprint()->occupancy($fixture));
        self::assertNull(new MatchFootprint()->occupancyMinutes($fixture));
    }

    private function fixture(FixtureHomeAway $homeAway, string $date, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setKickoffTime(null === $kickoff ? null : (DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null));

        return $fixture;
    }
}
