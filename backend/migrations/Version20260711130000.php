<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\SlotIdScoper;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * planning-versions (P0-5): re-key existing schedule_slot_template rows from the
 * engine's placement-global id to a PER-SCHEDULE id (uuid5(scheduleId:oldId)) so
 * they match the new ScheduleResultImporter scheme. Without this, the first
 * regeneration after deploy would fail to match a schedule's existing HARD rows
 * (old global id ≠ new scoped id) and leave orphaned duplicates.
 *
 * The row id is a leaf primary key (no child FK references it — diagnostics and
 * conflicts key on schedule_id), so the UPDATE is safe and cascade-free. Runs on
 * the admin connection (bypasses RLS) → every club's rows are re-keyed. Data
 * migration: down() cannot rebuild the original placement-global id (it lives
 * only in the engine), so it is an intentional no-op.
 */
final class Version20260711130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'planning-versions (P0-5): re-key schedule_slot_template ids per-schedule (uuid5(scheduleId:id)).';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, schedule_id FROM schedule_slot_template');
        foreach ($rows as $row) {
            $oldId = (string) $row['id'];
            $newId = SlotIdScoper::scope((string) $row['schedule_id'], $oldId);
            if ($newId !== $oldId) {
                $this->connection->executeStatement(
                    'UPDATE schedule_slot_template SET id = :new WHERE id = :old',
                    ['new' => $newId, 'old' => $oldId],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible: the original placement-global id is only reproducible by
        // the engine, not from the stored row. No-op (data migration).
    }
}
