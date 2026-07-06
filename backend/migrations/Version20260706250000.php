<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Match management palier A — PR-1 (global catalog):
 * league_match_window — federation match-kickoff windows (spec §6bis), the
 * envelope HARD a club inherits. GLOBAL reference table (no club_id/season_id)
 * → NO RLS, only a GRANT to app_user (same pattern as public_holiday /
 * school_holiday_period). Seeded from data/league-match-windows.aura.json.
 */
final class Version20260706250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match palier A: league_match_window global catalog (no RLS, GRANT only).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE league_match_window (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, league VARCHAR(24) NOT NULL, category VARCHAR(40) NOT NULL, level VARCHAR(20) NOT NULL, gender VARCHAR(10) DEFAULT NULL, day_of_week SMALLINT NOT NULL, kickoff_min TIME(0) WITHOUT TIME ZONE NOT NULL, kickoff_max TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_league_match_window ON league_match_window (league, category, level, gender, day_of_week, kickoff_min)');
        $this->addSql('CREATE INDEX idx_league_match_window_league ON league_match_window (league)');

        $hasRole = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        if ($hasRole) {
            // Public reference: readable/writable by app_user, no RLS policy (no club_id).
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON league_match_window TO app_user');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE league_match_window');
    }
}
