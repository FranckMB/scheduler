<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615010708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE team_tag (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, name VARCHAR(180) NOT NULL, color VARCHAR(20) DEFAULT NULL, is_system BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_team_tag_club ON team_tag (club_id)');
        $this->addSql('CREATE TABLE team_tag_assignment (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, team_id UUID NOT NULL, tag_id UUID NOT NULL, season_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_team_tag_assignment_team ON team_tag_assignment (team_id)');
        $this->addSql('CREATE INDEX idx_team_tag_assignment_tag ON team_tag_assignment (tag_id)');
        $this->addSql('CREATE INDEX idx_team_tag_assignment_season ON team_tag_assignment (season_id)');
        $this->addSql('ALTER TABLE sport_category ALTER gender TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE team DROP is_competition');
        $this->addSql('ALTER TABLE team ALTER gender TYPE VARCHAR(10)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA app_security');
        $this->addSql('DROP TABLE team_tag');
        $this->addSql('DROP TABLE team_tag_assignment');
        $this->addSql('ALTER TABLE sport_category ALTER gender TYPE VARCHAR(1)');
        $this->addSql('ALTER TABLE team ADD is_competition BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE team ALTER gender TYPE VARCHAR(20)');
    }
}
