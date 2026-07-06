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

        // Re-tag Corse clubs only. Métropole depts are always 4 numeric digits,
        // so "0020" isolates Corse; the alpha 2A/2B form never appears for
        // métropole. DOM/TOM clubs were already NULL (never tagged), so untouched.
        $this->addSql(
            'UPDATE club SET school_zone = \'CORSE\' '
            . 'WHERE school_zone = \'B\' '
            . 'AND (ffbb_club_code ~ \'^[A-Z]{3}0020\' OR upper(ffbb_club_code) ~ \'^[A-Z]{3}2[AB]\')',
        );
    }

    public function down(Schema $schema): void
    {
        // Reverse the Corse re-tag before shrinking; overseas rows (if any were
        // imported) must be cleared manually first — shrinking is lossy by nature.
        $this->addSql('UPDATE club SET school_zone = \'B\' WHERE school_zone = \'CORSE\'');
        $this->addSql('UPDATE school_holiday_period SET zone = \'B\' WHERE zone = \'CORSE\'');
        $this->addSql('ALTER TABLE club ALTER COLUMN school_zone TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE school_holiday_period ALTER COLUMN holiday_type TYPE VARCHAR(20)');
        $this->addSql('ALTER TABLE school_holiday_period ALTER COLUMN zone TYPE VARCHAR(1)');
    }
}
