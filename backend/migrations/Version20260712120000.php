<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Period-editable structure: backfill team_selection_initialized for periods that
 * predate the flag. An existing period was created under the old all-teams-active
 * behaviour — mark it CONFIGURED so the wizard's Fanion-only seed never re-fires on
 * it (which would silently deactivate teams the manager left active). Only NEW
 * periods (created after this migration) start uninitialised and get the seed.
 */
final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'period-editable structure: backfill calendar_entry.team_selection_initialized for existing periods.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE calendar_entry SET team_selection_initialized = true WHERE kind = \'period\'');
    }

    public function down(Schema $schema): void
    {
        // Data backfill — no meaningful inverse.
    }
}
