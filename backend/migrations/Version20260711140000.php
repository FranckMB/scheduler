<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot B: team_tag.axis (GENRE / NIVEAU / AGE) groups the constraint target picker.
 * Nullable — the 21 system tags map deterministically to one axis (backfilled
 * here by name); any tag outside the three axes stays null (no "other" bucket).
 * Runs on the admin connection (bypasses RLS) so every club's tags are backfilled.
 */
final class Version20260711140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot B: team_tag.axis (GENRE/NIVEAU/AGE) + backfill of the system tags by name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_tag ADD axis VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE team_tag SET axis = \'GENRE\' WHERE name IN (\'FEMININE\', \'MASCULINE\', \'MIXTE\')');
        $this->addSql('UPDATE team_tag SET axis = \'AGE\' WHERE name IN (\'EMB\', \'JEUNE\', \'SENIOR\', \'U9\', \'U11\', \'U13\', \'U15\', \'U18\', \'U21\')');
        $this->addSql('UPDATE team_tag SET axis = \'NIVEAU\' WHERE name IN (\'ELITE\', \'REGIONAL\', \'NATIONAL\', \'DEPARTEMENTAL\', \'LOISIR_ADULTE\', \'LOISIR_JEUNE\', \'HONNEUR\', \'PROMOTION\', \'PRE_REGION\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_tag DROP axis');
    }
}
