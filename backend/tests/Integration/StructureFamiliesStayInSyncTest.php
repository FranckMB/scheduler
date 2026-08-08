<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\StructureRestorer;
use App\Service\StructureSnapshotter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-09 — les familles structurelles sont énumérées TROIS fois, et le code l'avoue.
 *
 * `StructureSnapshotter::FAMILIES` dit ce qu'on SAUVEGARDE, `StructureRestorer::FAMILY_CLASS`
 * ce qu'on SAIT RELIRE, et `StructureRestorer::wipeStructure()` ce qu'on EFFACE avant de
 * restaurer — cette dernière se déclarant elle-même « mirror of ». Trois listes pour une
 * seule vérité : ce qui constitue la structure permanente d'un club.
 *
 * ⚠ **Le sens dangereux n'est pas symétrique.** Une famille présente au WIPE mais absente du
 * SNAPSHOT signifie que « Charger cette version » **supprime les lignes puis ne les restaure
 * pas** : `$data[$family] ?? []` rend un tableau vide *après* la suppression. Le gestionnaire
 * a demandé une restauration et reçoit une **perte de données**, sans erreur — c'est le seul
 * scénario de tout l'inventaire des duplications qui détruit du travail utilisateur.
 *
 * Les trois listes coïncident au 2026-08-08 : ce test ne corrige rien, il empêche. Il lit
 * `wipeStructure()` dans le source parce que la méthode est privée et que son contenu EST la
 * liste — la recopier ici en ferait une quatrième source de vérité.
 */
#[Group('phase1')]
final class StructureFamiliesStayInSyncTest extends TestCase
{
    private const string RESTORER_SOURCE = __DIR__ . '/../../src/Service/StructureRestorer.php';

    public function testWhatWeWipeIsExactlyWhatWeCanRestore(): void
    {
        $wiped = $this->wipedFamilies();
        $restorable = $this->restorableFamilies();

        $wipedButLost = array_values(array_diff($wiped, $restorable));

        self::assertSame([], $wipedButLost, \sprintf(
            "Ces familles sont EFFACÉES avant restauration mais absentes de la liste restaurable : %s.\n"
            . "« Charger cette version » supprimerait leurs lignes puis ne les remettrait pas —\n"
            . 'le gestionnaire demande une restauration et perd des données, sans une erreur.',
            implode(', ', $wipedButLost),
        ));
    }

    public function testWhatWeSnapshotIsExactlyWhatWeCanRestore(): void
    {
        self::assertSame($this->snapshotFamilies(), $this->restorableFamilies(), \sprintf(
            "Les familles sauvegardées et les familles relisibles ont divergé.\n"
            . "Une famille snapshotée mais non restaurable est écrite pour rien ; une famille\n"
            . 'restaurable mais non snapshotée se restaure toujours vide. %s',
            'Aligner StructureSnapshotter::FAMILIES et StructureRestorer::FAMILY_CLASS.',
        ));
    }

    /**
     * Le wipe doit couvrir tout ce qu'on restaure — sinon la restauration EMPILE sur des
     * lignes existantes au lieu de remplacer, et le club se retrouve avec des doublons.
     */
    public function testWhatWeRestoreIsExactlyWhatWeWipe(): void
    {
        $notWiped = array_values(array_diff($this->restorableFamilies(), $this->wipedFamilies()));

        self::assertSame([], $notWiped, \sprintf(
            "Ces familles sont restaurées sans être effacées d'abord : %s.\n"
            . 'La restauration empilerait sur les lignes existantes au lieu de les remplacer.',
            implode(', ', $notWiped),
        ));
    }

    /** @return list<string> */
    private function snapshotFamilies(): array
    {
        /** @var list<string> $families */
        $families = new ReflectionClass(StructureSnapshotter::class)->getConstant('FAMILIES');
        sort($families);

        self::assertNotEmpty($families, 'StructureSnapshotter::FAMILIES est vide.');

        return $families;
    }

    /** @return list<string> */
    private function restorableFamilies(): array
    {
        /** @var array<string, string> $map */
        $map = new ReflectionClass(StructureRestorer::class)->getConstant('FAMILY_CLASS');
        $families = array_values($map);
        sort($families);

        self::assertNotEmpty($families, 'StructureRestorer::FAMILY_CLASS est vide.');

        return $families;
    }

    /**
     * Les familles réellement supprimées par `wipeStructure()`, lues dans le source : la
     * méthode est privée, et son CORPS est la liste — la recopier ferait une 4e source.
     *
     * @return list<string>
     */
    private function wipedFamilies(): array
    {
        $source = file_get_contents(self::RESTORER_SOURCE);
        self::assertIsString($source, 'StructureRestorer est illisible.');

        $body = preg_match('/private function wipeStructure\(.*?\n    \}/s', $source, $matches);
        self::assertSame(1, $body, 'wipeStructure() a disparu ou changé de forme — ce test doit suivre, pas se taire.');

        preg_match_all('/deleteFamily\((\w+)::class/', $matches[0], $found);
        $families = array_values(array_unique(array_map(
            static fn (string $short): string => 'App\\Entity\\' . $short,
            $found[1],
        )));
        sort($families);

        self::assertNotEmpty($families, 'wipeStructure() ne supprime plus rien — la restauration empilerait.');

        return $families;
    }
}
