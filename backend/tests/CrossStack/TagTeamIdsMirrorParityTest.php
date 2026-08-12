<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\TeamTagResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-88 — CÔTÉ BACKEND de la parité mécanique du groupement « nom de tag → équipes ».
 *
 * Le foyer FRONT `shared/lib/tagTeamIds.ts::buildTagTeamIds` (utilisé par
 * `applicableConstraints` ET `PeriodStructure`) est le miroir client de la résolution
 * `TeamTagResolver`. `teamIdsByTagName` en est le foyer serveur PUR. Les MÊMES cas
 * (`tagTeamIds.parity.json`) les traversent : changer le groupement d'un seul côté rougit
 * ce côté-là. C'est la redérivation qui a ouvert P4-88 (un panneau annonçant une contrainte
 * CLUB+tag sur une équipe non taguée) — désormais opposable.
 *
 * Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class TagTeamIdsMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/shared/lib/tagTeamIds.parity.json';

    public function testBackendGroupingMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            /** @var array<string, list<string>> $expected */
            $expected = $case['expected'];
            foreach ($expected as $name => $ids) {
                sort($ids);
                $expected[$name] = $ids;
            }
            ksort($expected);

            $actual = TeamTagResolver::teamIdsByTagName($case['tags'], $case['assignments']);
            ksort($actual);

            self::assertSame($expected, $actual, \sprintf(
                "PARITÉ ROMPUE (« %s ») : le groupement backend diverge du foyer front.\n"
                . 'Front `buildTagTeamIds` et backend `TeamTagResolver::teamIdsByTagName` doivent coïncider sur tagTeamIds.parity.json.',
                (string) $case['name'],
            ));
        }
    }

    /** @return list<array{name: string, tags: list<array{id: string, name: string}>, assignments: list<array{teamId: string, tagId: string}>, expected: array<string, list<string>>}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, tags: list<array{id: string, name: string}>, assignments: list<array{teamId: string, tagId: string}>, expected: array<string, list<string>>}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'tagTeamIds.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
