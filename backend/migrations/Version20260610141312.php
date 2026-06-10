<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610141312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX uniq_club_ffbb_club_code ON club (ffbb_club_code)');
        $this->addSql('ALTER TABLE team ADD level VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE team ADD is_competition BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE team ADD size VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE team_constraint ADD source VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE venue ADD can_split BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA app_security');
        $this->addSql('DROP INDEX uniq_club_ffbb_club_code');
        $this->addSql('ALTER TABLE team DROP level');
        $this->addSql('ALTER TABLE team DROP is_competition');
        $this->addSql('ALTER TABLE team DROP size');
        $this->addSql('ALTER TABLE team_constraint DROP source');
        $this->addSql('ALTER TABLE venue DROP can_split');
    }
}
