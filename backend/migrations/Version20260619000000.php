<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migrate team.level = 'LOISIR' rows to either 'LOISIR_ADULTE' or 'LOISIR_JEUNE'
 * based on a name-pattern classification rule (U-category / youth keywords → LOISIR_JEUNE,
 * all remaining LOISIR rows → LOISIR_ADULTE).
 */
final class Version20260619000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split team.level LOISIR into LOISIR_JEUNE (youth) and LOISIR_ADULTE (adult)';
    }

    public function up(Schema $schema): void
    {
        // U-category teams → LOISIR_JEUNE
        $this->addSql(
            'UPDATE team SET level = \'LOISIR_JEUNE\'
             WHERE level = \'LOISIR\'
               AND (name ILIKE \'%U9%\' OR name ILIKE \'%U11%\' OR name ILIKE \'%U13%\'
                 OR name ILIKE \'%U15%\' OR name ILIKE \'%U18%\' OR name ILIKE \'%U21%\'
                 OR name ILIKE \'%Baby%\' OR name ILIKE \'%Micro%\' OR name ILIKE \'%Academie%\')',
        );

        // All remaining LOISIR → LOISIR_ADULTE
        $this->addSql(
            'UPDATE team SET level = \'LOISIR_ADULTE\'
             WHERE level = \'LOISIR\'',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE team SET level = \'LOISIR\'
             WHERE level IN (\'LOISIR_ADULTE\', \'LOISIR_JEUNE\')',
        );
    }
}
