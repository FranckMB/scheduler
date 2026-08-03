<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Service\Basketball\FfbbSeasonCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FFBB « 26-27 » ↔ our July-15 season pivot (appariement §8.2, closed): one
 * mapping, one place — 2026 is the season-year of « 26-27 », nothing else.
 */
#[Group('unit')]
final class FfbbSeasonCodeTest extends TestCase
{
    public function testSeasonYearMapsToTheFfbbCode(): void
    {
        self::assertSame('26-27', FfbbSeasonCode::fromSeasonYear(2026));
        self::assertSame('25-26', FfbbSeasonCode::fromSeasonYear(2025));
        self::assertSame('99-00', FfbbSeasonCode::fromSeasonYear(2099)); // century rollover
    }

    public function testMatchesIsStrict(): void
    {
        self::assertTrue(FfbbSeasonCode::matchesSeasonYear('26-27', 2026));
        self::assertFalse(FfbbSeasonCode::matchesSeasonYear('25-26', 2026));
        self::assertFalse(FfbbSeasonCode::matchesSeasonYear(null, 2026));
    }
}
