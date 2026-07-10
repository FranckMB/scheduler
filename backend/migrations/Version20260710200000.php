<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot C: FFBB autofill. Adds institutional club fields (postal_code/city/website)
 * and the shared reference tables ffbb_league / ffbb_committee (public FFBB data,
 * no club_id → outside RLS, like `club`/`user`; keyed on the FFBB code for
 * cache-first reuse across clubs).
 */
final class Version20260710200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot C: FFBB autofill — club institutional fields + shared ffbb_league/ffbb_committee reference tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club
            ADD postal_code VARCHAR(16) DEFAULT NULL,
            ADD city VARCHAR(120) DEFAULT NULL,
            ADD website VARCHAR(255) DEFAULT NULL,
            ADD latitude DOUBLE PRECISION DEFAULT NULL,
            ADD longitude DOUBLE PRECISION DEFAULT NULL');

        $this->addSql('CREATE TABLE ffbb_league (
            id UUID NOT NULL,
            code VARCHAR(24) NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) DEFAULT NULL,
            postal_code VARCHAR(16) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            email VARCHAR(180) DEFAULT NULL,
            logo_url VARCHAR(255) DEFAULT NULL,
            fetched_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ffbb_league_code ON ffbb_league (code)');

        $this->addSql('CREATE TABLE ffbb_committee (
            id UUID NOT NULL,
            code VARCHAR(24) NOT NULL,
            league_code VARCHAR(24) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) DEFAULT NULL,
            postal_code VARCHAR(16) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            email VARCHAR(180) DEFAULT NULL,
            logo_url VARCHAR(255) DEFAULT NULL,
            fetched_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ffbb_committee_code ON ffbb_committee (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ffbb_committee');
        $this->addSql('DROP TABLE ffbb_league');
        $this->addSql('ALTER TABLE club DROP postal_code, DROP city, DROP website, DROP latitude, DROP longitude');
    }
}
