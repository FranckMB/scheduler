<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UX-02 data repair: null out any Season.baseline_schedule_id that points at a
 * period OVERLAY (schedule.calendar_entry_id IS NOT NULL). An overlay is a
 * bounded exception plan and must never be the season baseline; a stale pointer
 * (created before SetBaselineController rejected overlays) silently corrupted
 * conflict detection (MatchConflictDetector.effectiveScheduleId /
 * CalendarEntryConflictsController fall back to the baseline) and showed an
 * empty "★ · période" as the main plan. NULL-ing is the safe repair: the
 * detectors then short-circuit to empty and the cockpit prompts the manager to
 * re-designate a real season plan.
 *
 * No schema change. Runs on the admin connection (bypasses RLS) so it repairs
 * every club, not just the request tenant.
 */
final class Version20260707130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UX-02: clear Season.baseline_schedule_id when it points at a period overlay.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE season SET baseline_schedule_id = NULL
            WHERE baseline_schedule_id IN (
                SELECT id FROM schedule WHERE calendar_entry_id IS NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible data repair: the previous (incorrect) overlay pointers are
        // not restored — re-designating a baseline is an explicit user action.
    }
}
