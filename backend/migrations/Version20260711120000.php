<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * planning-versions (overlay versions): a period may now carry SEVERAL overlay
 * versions (V1, V2…) like a season plan, with CalendarEntry.overlay_schedule_id
 * pointing at the ACTIVE one. Drop the partial-unique index that enforced "at
 * most one overlay per period" — the in-flight sibling guard (409) at the app
 * level now backstops a concurrent double POST, exactly as for season versions
 * (which never had such a DB index).
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'planning-versions: allow several overlay versions per period (drop uniq_schedule_calendar_entry).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_schedule_calendar_entry');
        // Non-unique index kept for the per-entry lookups (overlay versions of a period).
        $this->addSql('CREATE INDEX idx_schedule_calendar_entry ON schedule (calendar_entry_id) WHERE calendar_entry_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_schedule_calendar_entry');
        $this->addSql('CREATE UNIQUE INDEX uniq_schedule_calendar_entry ON schedule (calendar_entry_id) WHERE calendar_entry_id IS NOT NULL');
    }
}
