<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PublicHolidayMapper;
use App\Service\SchoolZoneResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class PublicHolidayMapperTest extends TestCase
{
    private PublicHolidayMapper $mapper;

    public function testYearWindowIsCalendarYearNoRoll(): void
    {
        // Fériés are keyed by calendar year — no August roll (unlike school years).
        self::assertSame([2026, 2027], $this->mapper->yearWindow(new DateTimeImmutable('2026-07-06')));
        self::assertSame([2026, 2027], $this->mapper->yearWindow(new DateTimeImmutable('2026-12-31')));
        self::assertSame([2026, 2027], $this->mapper->yearWindow(new DateTimeImmutable('2026-01-01')));
    }

    public function testNationalHolidaysFilterToWindow(): void
    {
        $metropole = [
            '2026-01-01' => '1er janvier',
            '2026-05-14' => 'Ascension',
            '2028-01-01' => '1er janvier', // out of window
            'not-a-date' => 'garbage',      // malformed
            '2026-07-14' => '   ',          // blank label
        ];

        $rows = $this->mapper->nationalHolidays($metropole, 2026, 2027);

        $dates = array_map(static fn (array $r): string => $r['date']->format('Y-m-d'), $rows);
        sort($dates);
        self::assertSame(['2026-01-01', '2026-05-14'], $dates);
    }

    public function testTerritoryExtrasAreTheDiffAgainstMetropole(): void
    {
        $metropole = [
            '2026-01-01' => '1er janvier',
            '2026-05-14' => 'Ascension',
        ];
        $guadeloupe = [
            '2026-01-01' => '1er janvier',                 // shared → dropped
            '2026-05-14' => 'Ascension',                   // shared → dropped
            '2026-05-27' => 'Abolition de l\'esclavage',   // extra → kept
            '2028-05-27' => 'Abolition de l\'esclavage',   // extra but out of window → dropped
        ];

        $rows = $this->mapper->territoryExtras($metropole, $guadeloupe, 2026, 2027);

        self::assertCount(1, $rows);
        self::assertSame('2026-05-27', $rows[0]['date']->format('Y-m-d'));
        self::assertSame('Abolition de l\'esclavage', $rows[0]['label']);
    }

    public function testEveryTerritoryZoneIsInTheCanonicalCatalog(): void
    {
        self::assertCount(9, PublicHolidayMapper::TERRITORY_FILE_TO_ZONE);
        foreach (PublicHolidayMapper::TERRITORY_FILE_TO_ZONE as $file => $code) {
            self::assertContains($code, SchoolZoneResolver::ZONES, "territory {$file} maps to a non-canonical zone");
        }
    }

    protected function setUp(): void
    {
        $this->mapper = new PublicHolidayMapper;
    }
}
