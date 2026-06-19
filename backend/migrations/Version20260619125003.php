<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619125003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE venue_training_slot (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, duration_minutes INT NOT NULL, capacity INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_training_slot_club_venue ON venue_training_slot (club_id, venue_id)');
        $this->addSql('DROP TABLE venue_availability');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE venue_availability (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, end_time TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_venue_availability_club_venue ON venue_availability (club_id, venue_id)');
        $this->addSql('DROP TABLE venue_training_slot');
    }
}
