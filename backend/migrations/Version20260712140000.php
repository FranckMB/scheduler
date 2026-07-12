<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unified planning naming (types-de-planning.md E6): the plan's name now lives on
 * Schedule.name (one editable name per planning, socle + overlays), so Season.planningName
 * is removed. Backfill first — a manager-chosen planningName is copied onto the season's
 * baseline schedule so it survives — then drop the column.
 */
final class Version20260712140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'unified planning naming: backfill season.planning_name onto the baseline schedule, drop the column.';
    }

    public function up(Schema $schema): void
    {
        // Backfill: a chosen planningName becomes the name of the season's LATEST socle
        // schedule (calendar_entry_id IS NULL) — not only the baseline, so a name chosen
        // before any validation (baseline still NULL) is not lost on the drop below.
        $this->addSql(
            'UPDATE schedule s SET name = se.planning_name FROM season se '
            . 'WHERE se.planning_name IS NOT NULL AND se.planning_name <> \'\' '
            . 'AND s.id = (SELECT s2.id FROM schedule s2 WHERE s2.season_id = se.id AND s2.calendar_entry_id IS NULL ORDER BY s2.created_at DESC LIMIT 1)',
        );
        $this->addSql('ALTER TABLE season DROP planning_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season ADD planning_name VARCHAR(120) DEFAULT NULL');
    }
}
