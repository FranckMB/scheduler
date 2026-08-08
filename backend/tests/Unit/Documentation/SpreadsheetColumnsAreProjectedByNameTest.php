<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use App\Service\SpreadsheetGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-18 — les colonnes de l'export XLSX étaient tenues par QUATRE listes : les en-têtes, deux
 * tuples de valeurs positionnels, et une plage de style `'A1:G1'` en dur.
 *
 * ⚑ **Le décalage produit un fichier valide.** Insérer une colonne dans les en-têtes sans
 * toucher les tuples ne lève rien : Excel s'ouvre, les titres sont là, et le gestionnaire lit
 * des heures sous « Gymnase ». Aucun test ne rougit — l'export est faux et se donne pour bon.
 *
 * Les lignes se construisent désormais par NOM de colonne et se projettent sur `HEADERS`.
 * Reste un risque, et un seul : une clé de ligne qui n'est PLUS une colonne. Elle ne casse
 * rien, elle disparaît juste du fichier. C'est ce que ce test interdit.
 */
#[Group('phase1')]
final class SpreadsheetColumnsAreProjectedByNameTest extends TestCase
{
    public function testEveryCellKeyIsAnActualColumn(): void
    {
        $source = file_get_contents(new ReflectionClass(SpreadsheetGenerator::class)->getFileName() ?: '');
        self::assertIsString($source);

        /** @var list<string> $headers */
        $headers = new ReflectionClass(SpreadsheetGenerator::class)->getConstant('HEADERS');

        // Les clés écrites dans les tableaux `'cells' => [...]`, telles que le code les pose.
        preg_match_all('/^\s{20}\'([^\']+)\' => /m', $source, $keys);
        $written = array_values(array_unique($keys[1]));

        self::assertNotEmpty($written, 'Aucune clé de cellule trouvée — si la construction des lignes a changé de forme, mettre ce test à jour plutôt que le retirer.');

        $orphans = array_values(array_diff($written, $headers));

        self::assertSame([], $orphans, \sprintf(
            "Ces clés de cellule ne correspondent à aucune colonne de l'export : %s.\n"
            . "Elles sont calculées puis jetées : la donnée n'apparaît nulle part dans le fichier,\n"
            . 'et rien ne le signale — ni erreur, ni cellule vide, ni test rouge.',
            implode(', ', $orphans),
        ));
    }

    /**
     * Toute colonne annoncée par un en-tête doit être renseignée par au moins une forme de
     * ligne. Une colonne que personne n'écrit sort vide sur TOUTES les lignes — moins grave
     * qu'un décalage, mais c'est un titre qui promet une donnée absente.
     */
    public function testEveryColumnIsFilledBySomeRowShape(): void
    {
        $source = file_get_contents(new ReflectionClass(SpreadsheetGenerator::class)->getFileName() ?: '');
        self::assertIsString($source);

        /** @var list<string> $headers */
        $headers = new ReflectionClass(SpreadsheetGenerator::class)->getConstant('HEADERS');

        preg_match_all('/^\s{20}\'([^\']+)\' => /m', $source, $keys);

        $never = array_values(array_diff($headers, $keys[1]));

        self::assertSame([], $never, \sprintf(
            'Ces colonnes ont un en-tête mais aucune ligne ne les renseigne : %s. Le titre promet une donnée que le fichier ne contient jamais.',
            implode(', ', $never),
        ));
    }
}
