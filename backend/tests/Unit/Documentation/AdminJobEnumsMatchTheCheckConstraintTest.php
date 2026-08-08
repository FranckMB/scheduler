<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use App\Enum\AdminJobSource;
use App\Enum\AdminJobStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D-19 — les valeurs de `admin_job_run` sont déclarées deux fois : par l'enum PHP et par la
 * contrainte `CHECK` de la migration. Les enums ferment les littéraux dispersés dans le code ;
 * ce test ferme la dernière copie, celle qui vit dans la base.
 *
 * ⚑ **Un seul des deux sens est bruyant.** Un cas PHP absent du `CHECK` lève une contrainte
 * Postgres dès la première écriture. L'inverse est muet : le `CHECK` accepte un statut que
 * plus aucun code ne lit, une exécution ratée cesse d'apparaître dans le flux d'erreurs
 * système, et la console d'exploitation affiche « aucune erreur ». Ce test regarde **les deux**.
 *
 * ⚠ Il lit la migration de CRÉATION. Si une migration ultérieure redéfinit le `CHECK`
 * (`DROP CONSTRAINT` puis `ADD`), c'est elle qui fait foi et c'est ce test qu'il faut
 * repointer — pas l'enum qu'il faut aligner à l'aveugle sur une contrainte périmée.
 */
#[Group('phase1')]
final class AdminJobEnumsMatchTheCheckConstraintTest extends TestCase
{
    private const string CREATION_MIGRATION = __DIR__ . '/../../../migrations/Version20260716120000.php';

    public function testTheStatusEnumMatchesTheDatabaseCheck(): void
    {
        $this->assertSameValues(AdminJobStatus::values(), $this->checkConstraintValues('chk_admin_job_run_status'), 'statut');
    }

    public function testTheSourceEnumMatchesTheDatabaseCheck(): void
    {
        $this->assertSameValues(AdminJobSource::values(), $this->checkConstraintValues('chk_admin_job_run_source'), 'source');
    }

    /**
     * La porte console n'offre que `cli` et `scheduled` — un opérateur ne doit pas pouvoir se
     * déclarer `superadmin` et inscrire dans l'historique une action humaine que personne n'a
     * faite. Le sous-ensemble est VOULU : il se dérive de l'enum, et ce test épingle la raison
     * pour qu'un futur lecteur ne le « complète » pas en croyant réparer un oubli.
     */
    public function testTheCommandLineRefusesTheIdentityBearingSource(): void
    {
        self::assertSame(AdminJobSource::CLI, AdminJobSource::fromCommandLine('cli'));
        self::assertSame(AdminJobSource::SCHEDULED, AdminJobSource::fromCommandLine('scheduled'));
        self::assertNull(AdminJobSource::fromCommandLine('superadmin'), 'La console ne doit jamais pouvoir se déclarer superadmin : le run porterait un super_admin_id qu\'aucun humain n\'a autorisé.');
        self::assertNull(AdminJobSource::fromCommandLine('nope'));
    }

    /**
     * @param list<string> $php
     * @param list<string> $sql
     */
    private function assertSameValues(array $php, array $sql, string $what): void
    {
        sort($php);
        sort($sql);

        self::assertSame($php, $sql, \sprintf(
            "L'enum PHP des %ss de job et la contrainte CHECK de la base ont divergé.\n"
            . "PHP : %s\nSQL : %s\n\n"
            . "Une valeur présente en PHP et absente du CHECK casse bruyamment à l'écriture.\n"
            . "L'inverse est MUET : un statut que le CHECK accepte mais que plus personne ne lit\n"
            . 'fait disparaître des exécutions ratées du flux d\'erreurs système, en silence.',
            $what,
            implode(', ', $php),
            implode(', ', $sql),
        ));
    }

    /** @return list<string> */
    private function checkConstraintValues(string $constraint): array
    {
        $migration = file_get_contents(self::CREATION_MIGRATION);
        self::assertIsString($migration, \sprintf('Illisible : %s', self::CREATION_MIGRATION));

        $found = preg_match(
            \sprintf('/CONSTRAINT %s CHECK \([a-z_]+ IN \((.*?)\)\)/', preg_quote($constraint, '/')),
            $migration,
            $matches,
        );
        self::assertSame(1, $found, \sprintf(
            'La contrainte %s a disparu de la migration de création. Si elle a été redéfinie ailleurs, repointer ce test sur la migration qui fait foi.',
            $constraint,
        ));

        preg_match_all('/\\\\?\'([a-z_]+)\\\\?\'/', $matches[1], $values);

        return $values[1];
    }
}
