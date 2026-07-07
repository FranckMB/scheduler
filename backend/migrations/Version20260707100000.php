<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Match management palier A — PR-4 (FBI import):
 * fixture.external_ref — the FBI match number, the import idempotence key.
 * Nullable (manual entries carry none); unique per (club, season, team) via a
 * partial index — team-scoped so an intra-club derby (same FBI number in both
 * teams' exports) can exist once per team. No RLS change (the table's policy
 * predicates on club_id, unchanged).
 */
final class Version20260707100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match palier A PR-4: fixture.external_ref (FBI number) + partial unique index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD external_ref VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_fixture_external_ref ON fixture (club_id, season_id, team_id, external_ref) WHERE external_ref IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_fixture_external_ref');
        $this->addSql('ALTER TABLE fixture DROP external_ref');
    }
}
