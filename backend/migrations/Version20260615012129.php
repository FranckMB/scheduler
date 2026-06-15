<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615012129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "constraint" (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, name VARCHAR(180) NOT NULL, description TEXT DEFAULT NULL, scope VARCHAR(20) NOT NULL, scope_target_id UUID DEFAULT NULL, family VARCHAR(20) NOT NULL, rule_type VARCHAR(20) NOT NULL, config JSON NOT NULL, created_by VARCHAR(80) DEFAULT NULL, source VARCHAR(80) DEFAULT NULL, source_occurrence_id VARCHAR(180) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_constraint_club_season ON "constraint" (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_constraint_scope_family ON "constraint" (scope, family)');
        $this->addSql('CREATE INDEX idx_constraint_rule_type ON "constraint" (rule_type)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA app_security');
        $this->addSql('DROP TABLE "constraint"');
    }
}
