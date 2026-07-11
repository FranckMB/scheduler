<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * planning-versions: the season tracks which version's structure is the CURRENTLY
 * LOADED context (★). Set on every COMPLETED season plan, re-pointed by "Charger
 * cette version" (reload a version's context without solving). Plain guid marker,
 * like baseline_schedule_id — no FK (a hard-deleted version simply leaves a stale
 * pointer that matches no row, so no ★ shows: safe degradation).
 */
final class Version20260711230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'planning-versions: season.live_context_schedule_id (loaded-context marker, ★).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season ADD live_context_schedule_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE season DROP live_context_schedule_id');
    }
}
