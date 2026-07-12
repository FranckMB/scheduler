<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Period-editable structure: calendar_entry.team_selection_initialized — durable
 * flag telling the wizard whether a period's team selection was already configured
 * (so the Fanion-only seed runs once, and never re-fires after the manager set the
 * period back to all-active — 0 sparse overrides is otherwise ambiguous).
 */
final class Version20260712110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'period-editable structure: calendar_entry.team_selection_initialized.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry ADD team_selection_initialized BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP team_selection_initialized');
    }
}
