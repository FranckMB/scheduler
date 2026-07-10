<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * planning-versions D1: the season carries the manager-chosen name of THE
 * season plan (one plan per season, declined in work versions — the schedule
 * rows are no longer individually named in the UI).
 */
final class Version20260710150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'planning-versions D1: season.planning_name (nullable manager-chosen plan name).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season ADD planning_name VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season DROP planning_name');
    }
}
