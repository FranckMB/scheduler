<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\CoachDoubleBookingDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-88 — CÔTÉ BACKEND de la parité mécanique de la règle de dédoublement de coach.
 *
 * `wizard/lib/coachDoubleBooking.ts::bookingsCollide` (front, prévient au clic) et
 * `CoachDoubleBookingDetector::bookingsCollide` (backend, bloque au récap) sont deux
 * implémentations INÉVITABLES de la même règle (la modale doit répondre sans aller-retour
 * réseau). Elles ne restaient alignées que par des suites JUMELLES « qui se ressemblent »
 * (P2-9) — ce qui ne garantit rien. Ici, les MÊMES cas (`coachDoubleBooking.parity.json`,
 * dans l'arbre front) traversent les DEUX implémentations : changer la règle d'un seul
 * côté rougit ce côté-là. C'est la parité MÉCANIQUE que demande la règle d'intention de
 * `.claude/rules/frontend.md`.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest` (miroir déclaré + parité).
 */
#[Group('contract')]
final class CoachDoubleBookingMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/wizard/lib/coachDoubleBooking.parity.json';

    public function testBackendBookingsCollideMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $expected = (bool) $case['collide'];
            $name = (string) $case['name'];

            self::assertSame($expected, CoachDoubleBookingDetector::bookingsCollide($case['a'], $case['b']), \sprintf(
                "PARITÉ ROMPUE (« %s ») : le backend ne rend pas le verdict partagé.\n"
                . "Front `bookingsCollide` et backend `CoachDoubleBookingDetector::bookingsCollide` doivent\n"
                . 'coïncider sur coachDoubleBooking.parity.json — sinon la modale autorise ce que le récap refuse.',
                $name,
            ));
            // Symétrique : l'ordre des deux séances ne change jamais le verdict.
            self::assertSame($expected, CoachDoubleBookingDetector::bookingsCollide($case['b'], $case['a']), $name . ' (ordre inversé)');
        }
    }

    /** @return list<array{name: string, a: array<string, mixed>, b: array<string, mixed>, collide: bool}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, a: array<string, mixed>, b: array<string, mixed>, collide: bool}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'coachDoubleBooking.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
