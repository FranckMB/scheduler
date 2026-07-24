<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Modes gymnase par période (feature #8) :
 * - venue_period_override — réglage sparse par (plan, gymnase) : DISABLED (le gymnase ne
 *   sert pas) ou BLANK (grille vierge). Pas de ligne = INHERIT, le défaut.
 * - venue_slot_period_exclusion — un créneau SAISONNIER écarté pour cette période ; le
 *   créneau lui-même n'est jamais supprimé.
 *
 * RLS FORCE sur les deux, comme toute table club_id (RlsIsolationTest les découvre).
 */
final class Version20260724120000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'venue period modes: venue_period_override + venue_slot_period_exclusion (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE venue_period_override (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID NOT NULL, venue_id UUID NOT NULL, mode VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_period_override ON venue_period_override (schedule_plan_id, venue_id)');
        $this->addSql('CREATE INDEX idx_venue_period_override_plan ON venue_period_override (schedule_plan_id)');

        $this->addSql('CREATE TABLE venue_slot_period_exclusion (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID NOT NULL, venue_training_slot_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_slot_period_exclusion ON venue_slot_period_exclusion (schedule_plan_id, venue_training_slot_id)');
        $this->addSql('CREATE INDEX idx_venue_slot_period_exclusion_plan ON venue_slot_period_exclusion (schedule_plan_id)');

        // RLS: FORCE + policy tenant_isolation adossée au GUC app.club_id (toute table
        // club_id hérite du motif — RlsIsolationTest le vérifie dynamiquement).
        $hasRole = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');
        if ($hasRole) {
            foreach (['venue_period_override', 'venue_slot_period_exclusion'] as $table) {
                $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO app_user', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf(
                    'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                    $table,
                    self::TENANT_PREDICATE,
                    self::TENANT_PREDICATE,
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte ses index, sa policy et ses GRANT — down symétrique du up.
        $this->addSql('DROP TABLE venue_slot_period_exclusion');
        $this->addSql('DROP TABLE venue_period_override');
    }
}
