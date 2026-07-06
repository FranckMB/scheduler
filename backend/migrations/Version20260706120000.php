<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * School-zone taxonomy widened from A/B/C to the 13 official zones (A/B/C, Corse,
 * and each DOM/TOM), so app:school-holidays:import can store overseas calendars.
 * Widens the two zone columns and re-tags Corse clubs B → CORSE (Corse now has
 * its own regime, no longer folded into zone B). Runs on the admin connection.
 * See specs/evolution/roadmap.md §2 and SchoolZoneResolver::ZONES.
 */
final class Version20260706120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen school-zone columns to the 13-zone taxonomy; re-tag Corse clubs B → CORSE.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE school_holiday_period ALTER COLUMN zone TYPE VARCHAR(24)');
        $this->addSql('ALTER TABLE school_holiday_period ALTER COLUMN holiday_type TYPE VARCHAR(40)');
        $this->addSql('ALTER TABLE club ALTER COLUMN school_zone TYPE VARCHAR(24)');

        // Re-tag Corse clubs only. Predicates mirror SchoolZoneResolver exactly
        // (which uppercases the code, then matches dept "0020" OR alpha 2A/2B
        // after optional padding digits): both branches use upper() and the same
        // '[0-9]*2[AB]' shape so no Corse club is missed. DOM/TOM clubs were
        // already NULL (never tagged), so untouched.
        $this->addSql(
            'UPDATE club SET school_zone = \'CORSE\' '
            . 'WHERE school_zone = \'B\' '
            . 'AND (upper(ffbb_club_code) ~ \'^[A-Z]{3}0020\' OR upper(ffbb_club_code) ~ \'^[A-Z]{3}[0-9]*2[AB]\')',
        );
    }

    public function down(Schema $schema): void
    {
        // Roll back to the A/B/C-only taxonomy. CORSE → B; every other new zone
        // (DOM/TOM) did not exist before this migration → clear it (clubs back to
        // NULL, holiday rows deleted) so the columns can shrink without truncation.
        $this->addSql('UPDATE club SET school_zone = \'B\' WHERE school_zone = \'CORSE\'');
        $this->addSql('UPDATE club SET school_zone = NULL WHERE school_zone NOT IN (\'A\', \'B\', \'C\')');
        $this->addSql('DELETE FROM school_holiday_period WHERE zone NOT IN (\'A\', \'B\', \'C\')');
        $this->addSql('ALTER TABLE club ALTER COLUMN school_zone TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE school_holiday_period ALTER COLUMN holiday_type TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE school_holiday_period ALTER COLUMN zone TYPE VARCHAR(1)');
    }
}
