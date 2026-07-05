<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cockpit palier B: schedule.calendar_entry_id — marks a schedule as the OVERLAY
 * of a CalendarEntry period (inverse of calendar_entry.overlay_schedule_id).
 * Plain guid column, no FK (schema convention). schedule is already under RLS.
 */
final class Version20260705120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cockpit palier B: schedule.calendar_entry_id (period overlay marker).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD calendar_entry_id UUID DEFAULT NULL');
        // Partial-unique: at most one overlay schedule per period entry — the DB
        // backstop behind the app-level 422 guard (defeats a concurrent double POST).
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_calendar_entry ON schedule (calendar_entry_id) WHERE calendar_entry_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_schedule_calendar_entry');
        $this->addSql('ALTER TABLE schedule DROP calendar_entry_id');
    }
}
