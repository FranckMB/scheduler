<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\OrphanPinGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-88 — CÔTÉ BACKEND de la parité mécanique du prédicat ÉTROIT « orpheline par triplet ».
 *
 * `wizard/lib/orphanReservations.ts::orphanReservationIds` (front, LISTE au récap) et
 * `OrphanPinGuard::orphanTripletIds` (backend) implémentent LE MÊME prédicat étroit :
 * « réservation dont (gymnase, jour, heure) ∉ grille ». Les MÊMES cas
 * (`orphanReservations.parity.json`) les traversent : changer le prédicat d'un seul côté
 * rougit ce côté-là.
 *
 * ⚠ SOUS-ENSEMBLE ASSUMÉ : le bloqueur COMPLET `OrphanPinGuard::firstOrphanMessage` refuse
 * EN PLUS pour les gymnases désactivés / jours de fermeture / verrous HARD (cas `divergence`
 * du fichier). Cette divergence est VOULUE et gardée par les tests d'`OrphanPinGuard`,
 * jamais ici — ce test ne pinne QUE le prédicat étroit partagé avec le front.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class OrphanReservationsMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/wizard/lib/orphanReservations.parity.json';

    public function testBackendNarrowPredicateMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            /** @var list<string> $expected */
            $expected = $case['orphanIds'];
            sort($expected);
            $actual = OrphanPinGuard::orphanTripletIds($case['reservations'], $case['slots']);
            sort($actual);

            self::assertSame($expected, $actual, \sprintf(
                "PARITÉ ROMPUE (« %s ») : le prédicat étroit backend ne rend pas les orphelines partagées.\n"
                . "Front `orphanReservationIds` et backend `OrphanPinGuard::orphanTripletIds` doivent\n"
                . 'coïncider sur orphanReservations.parity.json (le bloqueur COMPLET reste un sur-ensemble assumé).',
                (string) $case['name'],
            ));
        }
    }

    /** @return list<array{name: string, slots: list<array<string, mixed>>, reservations: list<array<string, mixed>>, orphanIds: list<string>}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, slots: list<array<string, mixed>>, reservations: list<array<string, mixed>>, orphanIds: list<string>}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'orphanReservations.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
