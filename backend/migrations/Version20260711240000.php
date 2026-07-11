<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * planning-versions: backfill season.live_context_schedule_id for seasons that
 * predate the column — point each at its latest FINISHED season plan (COMPLETED
 * or VALIDATED). Without this a pre-deploy season has a NULL pointer: no ★ shows
 * and "Charger cette version" would wrongly enable on the already-current
 * version. The frontend also falls back to the latest visible plan, but the
 * backfill makes the persisted state correct from the first load.
 */
final class Version20260711240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'planning-versions: backfill season.live_context_schedule_id (latest finished season plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE season s SET live_context_schedule_id = (
                SELECT sc.id FROM schedule sc
                WHERE sc.season_id = s.id
                  AND sc.calendar_entry_id IS NULL
                  AND sc.status IN ('COMPLETED', 'VALIDATED')
                ORDER BY sc.created_at DESC
                LIMIT 1
            )
            WHERE s.live_context_schedule_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Data backfill — no meaningful inverse (clearing would also wipe pointers
        // set by generations that ran after this migration).
    }
}
