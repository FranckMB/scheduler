<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot B: FFBB club info fields on `club` (committee, contacts, president,
 * correspondent, main venue). All nullable — manual entry now, FFBB autofill
 * in lot C. `club` has no club_id, so no RLS.
 */
final class Version20260710180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot B: FFBB club info fields on club (committee, contacts, president, correspondent, main venue).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club
            ADD committee_code VARCHAR(24) DEFAULT NULL,
            ADD contact_phone VARCHAR(32) DEFAULT NULL,
            ADD contact_email VARCHAR(180) DEFAULT NULL,
            ADD address VARCHAR(255) DEFAULT NULL,
            ADD correspondent_name VARCHAR(180) DEFAULT NULL,
            ADD correspondent_phone VARCHAR(32) DEFAULT NULL,
            ADD correspondent_email VARCHAR(180) DEFAULT NULL,
            ADD president_name VARCHAR(180) DEFAULT NULL,
            ADD president_phone VARCHAR(32) DEFAULT NULL,
            ADD president_email VARCHAR(180) DEFAULT NULL,
            ADD main_venue_name VARCHAR(180) DEFAULT NULL,
            ADD main_venue_address VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club
            DROP committee_code, DROP contact_phone, DROP contact_email, DROP address,
            DROP correspondent_name, DROP correspondent_phone, DROP correspondent_email,
            DROP president_name, DROP president_phone, DROP president_email,
            DROP main_venue_name, DROP main_venue_address');
    }
}
