<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\TeamLevel;
use App\Service\VenueUsage\HolidayRange;
use App\Service\VenueUsage\PeriodOverlay;
use App\Service\VenueUsage\UsageSlot;
use App\Service\VenueUsage\VenueUsageCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le calcul PUR des stats d'utilisation des gymnases (P3-22) : jour par jour,
 * Réalisé vs À venir, vacances neutralisées, overlay de période prioritaire,
 * semaine-enfant primant sa mère, plage partielle, ventilation par niveau.
 */
#[Group('phase1')]
final class VenueUsageCalculatorTest extends TestCase
{
    private VenueUsageCalculator $calc;

    /** Fenêtre de 2 semaines pleines : lun 05/01/2026 → dim 18/01/2026. */
    public function testSplitsRealisedFromUpcomingByVenueDayAndLevel(): void
    {
        $slots = [
            'S' => [
                new UsageSlot('V1', 1, 120, 'T_REG'),   // lundi, 2 h, régional
                new UsageSlot('V1', 3, 90, 'T_LOISIR'), // mercredi, 1,5 h, loisir
                new UsageSlot('V2', 1, 60, 'T_NONE'),   // lundi, 1 h, sans niveau
            ],
        ];

        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-18'),
            new DateTimeImmutable('2026-01-08'), // jeudi : semaine 1 déjà passée, semaine 2 à venir
            'A',
            'S',
            [],
            [],
            $slots,
            ['V1' => 'Mateo', 'V2' => 'Barros'],
            ['T_REG' => TeamLevel::REGIONAL, 'T_LOISIR' => TeamLevel::LOISIR_ADULTE, 'T_NONE' => null],
        );

        self::assertSame(['from' => '2026-01-05', 'to' => '2026-01-18', 'today' => '2026-01-08'], $result['range']);
        self::assertSame('A', $result['zone']);

        // Chaque créneau tombe une fois en semaine 1 (réalisé) et une fois en 2 (à venir).
        $v1 = $this->row($result['venues'], 'venueId', 'V1');
        self::assertSame('Mateo', $v1['name']);
        self::assertEqualsWithDelta(3.5, $v1['real'], 0.001);
        self::assertEqualsWithDelta(3.5, $v1['projected'], 0.001);
        self::assertEqualsWithDelta(7.0, $v1['total'], 0.001);
        $mon = $this->row($v1['byDay'], 'day', 1);
        self::assertEqualsWithDelta(2.0, $mon['real'], 0.001);
        self::assertEqualsWithDelta(2.0, $mon['projected'], 0.001);

        // Total par jour (le chiffre de négociation) : lundi 3 h + 3 h.
        $totalMon = $this->row($result['totalByDay'], 'day', 1);
        self::assertEqualsWithDelta(3.0, $totalMon['real'], 0.001);
        self::assertEqualsWithDelta(3.0, $totalMon['projected'], 0.001);

        self::assertEqualsWithDelta(4.5, $result['grandTotal']['real'], 0.001);
        self::assertEqualsWithDelta(4.5, $result['grandTotal']['projected'], 0.001);
        self::assertEqualsWithDelta(9.0, $result['grandTotal']['total'], 0.001);

        // Ventilation par niveau : une ligne par niveau qui a des heures, libellée serveur.
        $reg = $this->row($result['byLevel'], 'level', 'REGIONAL');
        self::assertSame('Régional', $reg['label']);
        self::assertEqualsWithDelta(4.0, $reg['total'], 0.001);
    }

    public function testUnlabeledTeamsBecomeAnUnclassifiedRow(): void
    {
        $result = $this->baseline(['T_NONE' => null]);

        $none = $this->row($result['byLevel'], 'level', null);
        self::assertSame('Non renseigné', $none['label']);
        self::assertGreaterThan(0.0, $none['total']);
    }

    public function testSchoolHolidaysCountAsZeroForFutureDays(): void
    {
        // Vacances sur la semaine 2 (à venir) → seule la semaine 1 compte.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-18'),
            new DateTimeImmutable('2026-01-08'),
            'A',
            'S',
            [],
            [new HolidayRange(new DateTimeImmutable('2026-01-12'), new DateTimeImmutable('2026-01-18'))],
            ['S' => [new UsageSlot('V1', 1, 120, 'T_REG')]],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        self::assertEqualsWithDelta(2.0, $result['grandTotal']['real'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['grandTotal']['projected'], 0.001);
    }

    public function testPastHolidayAlsoCountsZeroEvenThoughRealised(): void
    {
        // Décision fondateur : un jour de vacances PASSÉ vaut 0 h aussi en Réalisé.
        // Semaine 1 dans le passé (today = 18/01), mais couverte par des vacances.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-11'),
            new DateTimeImmutable('2026-01-18'), // toute la fenêtre est passée
            'A',
            'S',
            [],
            [new HolidayRange(new DateTimeImmutable('2026-01-05'), new DateTimeImmutable('2026-01-11'))],
            ['S' => [new UsageSlot('V1', 1, 120, 'T_REG')]],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        self::assertEqualsWithDelta(0.0, $result['grandTotal']['real'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['grandTotal']['total'], 0.001);
    }

    public function testNoHolidaysMeansNoNeutralisation(): void
    {
        // Zone nulle côté contrôleur → holidays vide : la grille compte partout.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-11'),
            new DateTimeImmutable('2026-01-18'),
            null,
            'S',
            [],
            [],
            ['S' => [new UsageSlot('V1', 1, 120, 'T_REG')]],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        self::assertNull($result['zone']);
        self::assertEqualsWithDelta(2.0, $result['grandTotal']['total'], 0.001);
    }

    public function testActiveOverlayReplacesTheSeasonGridOnItsDates(): void
    {
        // Overlay sur la semaine 2 : son créneau (30 min) remplace celui de saison
        // (120 min) le lundi 12/01 ; le lundi 05/01 reste sur la saison.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-18'),
            new DateTimeImmutable('2026-06-01'), // tout est passé → réalisé, pour simplifier
            'A',
            'S',
            [new PeriodOverlay(new DateTimeImmutable('2026-01-12'), new DateTimeImmutable('2026-01-18'), null, 'OVL')],
            [],
            [
                'S' => [new UsageSlot('V1', 1, 120, 'T_REG')],
                'OVL' => [new UsageSlot('V1', 1, 30, 'T_REG')],
            ],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        // Lundi : 2 h (saison, 05/01) + 0,5 h (overlay, 12/01) = 2,5 h.
        $mon = $this->row($this->row($result['venues'], 'venueId', 'V1')['byDay'], 'day', 1);
        self::assertEqualsWithDelta(2.5, $mon['total'], 0.001);
    }

    public function testChildWeekOverridesItsMotherPeriod(): void
    {
        // Le mercredi 14/01 est couvert par la mère (semaine) ET par la semaine-enfant
        // (ce seul jour) : l'enfant, plus étroit, prime → 45 min, pas 90.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-14'),
            new DateTimeImmutable('2026-01-14'),
            new DateTimeImmutable('2026-06-01'),
            'A',
            null,
            [
                new PeriodOverlay(new DateTimeImmutable('2026-01-12'), new DateTimeImmutable('2026-01-18'), null, 'MOM'),
                new PeriodOverlay(new DateTimeImmutable('2026-01-14'), new DateTimeImmutable('2026-01-14'), 'mother-id', 'CHILD'),
            ],
            [],
            [
                'MOM' => [new UsageSlot('V1', 3, 90, 'T_REG')],
                'CHILD' => [new UsageSlot('V1', 3, 45, 'T_REG')],
            ],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        self::assertEqualsWithDelta(0.75, $result['grandTotal']['total'], 0.001);
    }

    public function testPartialWeekOnlyCountsDaysInsideTheWindow(): void
    {
        // Fenêtre mer 07/01 → ven 09/01 : le lundi (hors fenêtre) ne compte pas.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-07'),
            new DateTimeImmutable('2026-01-09'),
            new DateTimeImmutable('2026-06-01'),
            'A',
            'S',
            [],
            [],
            ['S' => [new UsageSlot('V1', 1, 120, 'T_REG'), new UsageSlot('V1', 3, 60, 'T_REG')]],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        self::assertEqualsWithDelta(1.0, $result['grandTotal']['total'], 0.001);
        self::assertCount(1, $result['totalByDay']);
        self::assertSame(3, $result['totalByDay'][0]['day']);
    }

    public function testNoPointerYieldsEmptyStats(): void
    {
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-18'),
            new DateTimeImmutable('2026-01-08'),
            'A',
            null, // le plan SEASON n'a aucune version pointée
            [],
            [],
            [],
            [],
            [],
        );

        self::assertSame([], $result['venues']);
        self::assertSame([], $result['byLevel']);
        self::assertEqualsWithDelta(0.0, $result['grandTotal']['total'], 0.001);
    }

    public function testHoursAreRoundedToTwoDecimals(): void
    {
        // 50 min = 0,8333… h → 0,83.
        $result = $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-11'),
            new DateTimeImmutable('2026-06-01'),
            'A',
            'S',
            [],
            [],
            ['S' => [new UsageSlot('V1', 1, 50, 'T_REG')]],
            ['V1' => 'Mateo'],
            ['T_REG' => TeamLevel::REGIONAL],
        );

        self::assertSame(0.83, $result['grandTotal']['total']);
    }

    protected function setUp(): void
    {
        $this->calc = new VenueUsageCalculator;
    }

    /**
     * @param array<string, TeamLevel|null> $teamLevels
     *
     * @return array<string, mixed>
     */
    private function baseline(array $teamLevels): array
    {
        return $this->calc->calculate(
            new DateTimeImmutable('2026-01-05'),
            new DateTimeImmutable('2026-01-11'),
            new DateTimeImmutable('2026-06-01'),
            'A',
            'S',
            [],
            [],
            ['S' => [new UsageSlot('V2', 1, 60, array_key_first($teamLevels) ?? 'T')]],
            ['V2' => 'Barros'],
            $teamLevels,
        );
    }

    /**
     * Trouve la première ligne dont la clé vaut la valeur donnée.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function row(array $rows, string $key, mixed $value): array
    {
        foreach ($rows as $r) {
            if (($r[$key] ?? null) === $value) {
                return $r;
            }
        }
        self::fail(\sprintf('No row with %s = %s', $key, var_export($value, true)));
    }
}
