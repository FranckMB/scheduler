<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615114943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE IF EXISTS coach_unavailability');
        $this->addSql('DROP TABLE IF EXISTS team_constraint');
        $this->addSql('DROP TABLE IF EXISTS venue_availability');
        $this->addSql('DROP TABLE IF EXISTS venue_closure');
        $this->addSql('DROP TABLE IF EXISTS venue_constraint');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE coach_unavailability (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, coach_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, end_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE TABLE team_constraint (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_id UUID NOT NULL, type VARCHAR(20) NOT NULL, day_of_week SMALLINT DEFAULT NULL, start_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, end_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, venue_id UUID DEFAULT NULL, reason TEXT DEFAULT NULL, created_by UUID DEFAULT NULL, source_occurrence_id UUID DEFAULT NULL, source VARCHAR(20) DEFAULT NULL, severity VARCHAR(30) DEFAULT NULL)');
        $this->addSql('CREATE TABLE venue_availability (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, end_time TIME(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE TABLE venue_closure (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, date_start DATE NOT NULL, date_end DATE NOT NULL, reason TEXT DEFAULT NULL)');
        $this->addSql('CREATE TABLE venue_constraint (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, constraint_type VARCHAR(30) NOT NULL, constraint_value VARCHAR(20) NOT NULL)');
    }
}
