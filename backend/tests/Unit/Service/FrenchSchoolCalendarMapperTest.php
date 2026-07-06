<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FrenchSchoolCalendarMapper;
use App\Service\SchoolZoneResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('phase1')]
final class FrenchSchoolCalendarMapperTest extends TestCase
{
    private FrenchSchoolCalendarMapper $mapper;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function zoneProvider(): iterable
    {
        yield 'zone A' => ['Zone A', 'A'];
        yield 'zone C' => ['Zone C', 'C'];
        yield 'corse' => ['Corse', 'CORSE'];
        yield 'guadeloupe' => ['Guadeloupe', 'GUADELOUPE'];
        yield 'reunion accent' => ['Réunion', 'REUNION'];
        yield 'nouvelle caledonie space' => ['Nouvelle Calédonie', 'NOUVELLE_CALEDONIE'];
        yield 'nouvelle-caledonie hyphen' => ['Nouvelle-Calédonie', 'NOUVELLE_CALEDONIE'];
        yield 'saint pierre' => ['Saint Pierre et Miquelon', 'SAINT_PIERRE_MIQUELON'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function holidayTypeProvider(): iterable
    {
        yield 'toussaint' => ['Vacances de la Toussaint', 'toussaint'];
        yield 'noel' => ['Vacances de Noël', 'noel'];
        yield 'hiver' => ['Vacances d\'Hiver', 'hiver'];
        yield 'printemps' => ['Vacances de Printemps', 'printemps'];
        yield 'ete' => ['Vacances d\'Été', 'ete'];
        yield 'ete austral' => ['Vacances d\'Été austral', 'ete_austral'];
        yield 'hiver austral' => ['Vacances d\'Hiver austral', 'hiver_austral'];
        yield 'carnaval' => ['Vacances de Carnaval', 'carnaval'];
        yield 'paques' => ['Vacances de Pâques', 'paques'];
        yield 'fevrier' => ['Vacances de Février', 'fevrier'];
        yield 'apres periode' => ['Vacances après 1ère période', 'apres_1ere_periode'];
        yield 'semaine en mai (no vacances prefix)' => ['Semaine en mai', 'semaine_en_mai'];
    }

    #[DataProvider('zoneProvider')]
    public function testMapZone(string $apiZone, string $expected): void
    {
        self::assertSame($expected, $this->mapper->mapZone($apiZone));
    }

    public function testMapZoneUnknownReturnsNull(): void
    {
        self::assertNull($this->mapper->mapZone('Zone D'));
        self::assertNull($this->mapper->mapZone(''));
    }

    public function testEveryMappedZoneIsInTheCanonicalCatalog(): void
    {
        foreach (self::zoneProvider() as [$apiZone, $expected]) {
            self::assertContains($this->mapper->mapZone($apiZone), SchoolZoneResolver::ZONES);
        }
    }

    #[DataProvider('holidayTypeProvider')]
    public function testMapHolidayType(string $description, string $expected): void
    {
        self::assertSame($expected, $this->mapper->mapHolidayType($description));
    }

    public function testRealVacationIsKept(): void
    {
        // Toussaint 2025: Sat 18 Oct → Mon 3 Nov (return) = ~10 business days.
        self::assertTrue($this->mapper->isVacation(
            'Vacances de la Toussaint',
            new DateTimeImmutable('2025-10-18'),
            new DateTimeImmutable('2025-11-03'),
        ));
    }

    public function testPontIsRejectedDespiteMultiDaySpan(): void
    {
        // Pont de l'Ascension 2026: Wed 13 → Sun 17 May = 5 calendar days but
        // only 3 business days (Wed/Thu/Fri) — and the "Pont" label guard also
        // rejects it. It is a manager/holiday concern, not a school vacation.
        self::assertFalse($this->mapper->isVacation(
            'Pont de l\'Ascension',
            new DateTimeImmutable('2026-05-13'),
            new DateTimeImmutable('2026-05-17'),
        ));
    }

    public function testShortBreakIsRejected(): void
    {
        // "Semaine en mai" 2-day occurrence: Thu 14 → Fri 15 (return) = 1 business day.
        self::assertFalse($this->mapper->isVacation(
            'Semaine en mai',
            new DateTimeImmutable('2026-05-14'),
            new DateTimeImmutable('2026-05-15'),
        ));
        // Single-day marker.
        self::assertFalse($this->mapper->isVacation(
            'Mi-carême',
            new DateTimeImmutable('2026-03-11'),
            new DateTimeImmutable('2026-03-11'),
        ));
    }

    public function testExactlyThreeBusinessDaysIsRejected(): void
    {
        // Mon → Thu (return) = Mon/Tue/Wed = 3 business days, not > 3.
        self::assertFalse($this->mapper->isVacation(
            'Vacances de Test',
            new DateTimeImmutable('2026-03-02'),
            new DateTimeImmutable('2026-03-05'),
        ));
        // Mon → Fri (return) = Mon/Tue/Wed/Thu = 4 business days, > 3.
        self::assertTrue($this->mapper->isVacation(
            'Vacances de Test',
            new DateTimeImmutable('2026-03-02'),
            new DateTimeImmutable('2026-03-06'),
        ));
    }

    public function testBusinessDaysSkipWeekends(): void
    {
        // Fri → Mon (return) = only Fri counts.
        self::assertSame(1, $this->mapper->businessDays(
            new DateTimeImmutable('2026-03-06'),
            new DateTimeImmutable('2026-03-09'),
        ));
    }

    public function testSchoolYearWindowBeforeAugustRollsBack(): void
    {
        // July is still the school year that started the previous calendar year.
        self::assertSame(
            ['2025-2026', '2026-2027'],
            $this->mapper->schoolYearWindow(new DateTimeImmutable('2026-07-06')),
        );
    }

    public function testSchoolYearWindowFromAugustRollsForward(): void
    {
        self::assertSame(
            ['2026-2027', '2027-2028'],
            $this->mapper->schoolYearWindow(new DateTimeImmutable('2026-08-15')),
        );
    }

    protected function setUp(): void
    {
        $this->mapper = new FrenchSchoolCalendarMapper;
    }
}
