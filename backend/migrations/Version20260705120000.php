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
        $this->addSql('CREATE INDEX idx_schedule_calendar_entry ON schedule (calendar_entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_schedule_calendar_entry');
        $this->addSql('ALTER TABLE schedule DROP calendar_entry_id');
    }
}
