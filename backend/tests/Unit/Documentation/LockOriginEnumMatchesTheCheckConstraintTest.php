<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use App\Enum\LockOrigin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les valeurs de `schedule_slot_template.lock_origin` sont déclarées deux fois : par l'enum
 * PHP `LockOrigin` et par la contrainte `CHECK` de la migration. Ce test ferme la copie qui
 * vit en base — même garde bidirectionnel que `AdminJobEnumsMatchTheCheckConstraintTest`.
 *
 * ⚑ Un seul des deux sens est bruyant. Un cas PHP absent du `CHECK` casse à la première
 * écriture ; l'inverse est muet (le `CHECK` accepte une valeur que plus aucun code ne lit).
 * Ce test regarde les DEUX.
 *
 * ⚠ Il lit la migration de CRÉATION de la colonne. Si une migration ultérieure redéfinit le
 * `CHECK`, c'est elle qui fait foi — repointer ce test dessus, ne pas aligner l'enum à
 * l'aveugle sur une contrainte périmée.
 */
#[Group('phase1')]
final class LockOriginEnumMatchesTheCheckConstraintTest extends TestCase
{
    private const string CREATION_MIGRATION = __DIR__ . '/../../../migrations/Version20260812120000.php';

    private const string CONSTRAINT = 'chk_schedule_slot_template_lock_origin';

    public function testTheEnumMatchesTheDatabaseCheck(): void
    {
        $php = LockOrigin::values();
        $sql = $this->checkConstraintValues();
        sort($php);
        sort($sql);

        self::assertSame($php, $sql, \sprintf(
            "L'enum PHP LockOrigin et la contrainte CHECK de la base ont divergé.\n"
            . "PHP : %s\nSQL : %s\n\n"
            . "Une valeur présente en PHP et absente du CHECK casse bruyamment à l'écriture ;\n"
            . 'l\'inverse est MUET : le CHECK accepte une origine que plus personne ne lit.',
            implode(', ', $php),
            implode(', ', $sql),
        ));
    }

    /** @return list<string> */
    private function checkConstraintValues(): array
    {
        $migration = file_get_contents(self::CREATION_MIGRATION);
        self::assertIsString($migration, \sprintf('Illisible : %s', self::CREATION_MIGRATION));

        // La forme nullable : CHECK (lock_origin IS NULL OR lock_origin IN ('A', 'B', ...)).
        $found = preg_match(
            \sprintf('/CONSTRAINT %s CHECK \(.*?IN \((.*?)\)\)/s', preg_quote(self::CONSTRAINT, '/')),
            $migration,
            $matches,
        );
        self::assertSame(1, $found, \sprintf(
            'La contrainte %s a disparu de la migration de création. Si elle a été redéfinie ailleurs, repointer ce test sur la migration qui fait foi.',
            self::CONSTRAINT,
        ));

        preg_match_all("/'([A-Z_]+)'/", $matches[1], $values);

        return $values[1];
    }
}
