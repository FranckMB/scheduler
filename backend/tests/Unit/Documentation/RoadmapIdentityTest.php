<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * UN IDENTIFIANT DE ROADMAP EST UNE CLÉ — il désigne un item, et un seul.
 *
 * ⚑ Ce test existe parce que la convention a cédé en silence : constaté le 2026-08-22,
 * **`P4-119` et `P5-19` désignaient chacun DEUX items différents**. Or `P4-119` est cité dans
 * une vingtaine d'endroits du code (`frontend/src/features/planning/*`, `frontend-spec.md`) :
 * un lecteur qui suit la référence depuis `api.ts` tombe sur une ligne de roadmap qui parle
 * d'autre chose. La référence ne pointe plus rien, sans que rien ne rougisse.
 *
 * Le mécanisme de la dérive est banal et se reproduira : deux passes rapprochées lisent le même
 * « dernier numéro », et l'attribuent toutes les deux. C'est précisément ce qu'une machine
 * détecte mieux qu'une relecture.
 *
 * Le second invariant — le compteur du titre — est l'exception fondateur du 2026-08-04 à la règle
 * anti-décompte (skill `documentation-update`) : « Roadmap (N) » est le seul décompte que le
 * dépôt s'autorise, parce qu'il répond d'un coup d'œil à « combien reste-t-il ». Un décompte faux
 * est pire que pas de décompte : il se lit sans être vérifié.
 */
#[Group('phase1')]
final class RoadmapIdentityTest extends TestCase
{
    private const ROADMAP = 'specs/evolution/roadmap.md';

    /**
     * Une ligne d'item : `| P4-119 | **…** | … |`. Le tableau porte aussi des lignes sans id
     * (en-têtes, séparateurs), et le compteur du titre ne doit compter que les items.
     *
     * ⚠ Le **suffixe littéral est signifiant** et fait partie de la clé : `P5-4b` est un
     * sous-item dérivé de `P5-4`, pas une coquille. L'oublier était mon premier essai — le
     * compteur du titre m'a alors accusé d'un écart qui venait de MA lecture, pas du fichier.
     */
    private const ITEM_LINE = '/^\| ([A-Z]+[0-9]*-[0-9]+[a-z]?) \|/';

    public function testEveryIdentifierDesignatesExactlyOneItem(): void
    {
        $seen = [];
        $duplicates = [];
        foreach ($this->itemLines() as $lineNumber => $id) {
            if (isset($seen[$id])) {
                $duplicates[] = \sprintf('%s : lignes %d et %d', $id, $seen[$id], $lineNumber);

                continue;
            }
            $seen[$id] = $lineNumber;
        }

        self::assertSame([], $duplicates, <<<'TXT'
            Ces identifiants désignent plusieurs items — ils ne sont donc plus des clés.

            Un identifiant est cité depuis le CODE, les commits et les PR : le réattribuer casse
            silencieusement toutes ces références. Renumérotez l'item qui n'est cité NULLE PART
            (`grep -rn "<id>" --include="*.php" --include="*.ts" --include="*.tsx" --include="*.py" --include="*.md" .`),
            en prenant le successeur du plus grand numéro de sa famille.

            Un trou de numérotation signifie « livré », par convention — il n'y a donc jamais lieu
            de recycler un numéro libre.
            TXT);
    }

    public function testTheHeaderCountMatchesWhatTheTableHolds(): void
    {
        if (1 !== preg_match('/^# Roadmap \((\d+)\)/m', $this->roadmap(), $matches)) {
            self::fail('le titre doit annoncer le nombre d\'items ouverts : « # Roadmap (N) — … »');
        }

        $announced = (int) $matches[1];
        $actual = \count($this->itemLines());

        self::assertSame($announced, $actual, \sprintf(<<<'TXT'
            Le titre annonce %d items ouverts, le tableau en contient %d.

            « Roadmap (N) » est le seul décompte que ce dépôt s'autorise, et il se lit sans être
            vérifié : chaque ligne supprimée le décrémente, chaque ligne ajoutée l'incrémente.
            TXT, $announced, $actual));
    }

    /** @return array<int, string> numéro de ligne (1-indexé) => identifiant */
    private function itemLines(): array
    {
        $items = [];
        foreach (explode("\n", $this->roadmap()) as $index => $line) {
            if (1 === preg_match(self::ITEM_LINE, $line, $matches)) {
                $items[$index + 1] = $matches[1];
            }
        }

        return $items;
    }

    private function roadmap(): string
    {
        $path = \dirname(__DIR__, 3) . '/../' . self::ROADMAP;
        $content = file_get_contents($path);
        self::assertIsString($content, self::ROADMAP . ' doit être lisible');

        return $content;
    }
}
