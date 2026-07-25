<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Collecte des doléances coachs (feature #10, lot C2).
 *
 * `coach_wish_campaign` — une campagne de collecte par période de vacances : semaines et
 * équipes retenues + date limite. Tenant, RLS FORCE classique.
 *
 * `coach_wish_token` — un lien personnel par coach (secret en clair, cf. entité). RLS
 * HYBRIDE, patron `club_user` : SELECT ouvert (le token doit se relire AVANT que le GUC
 * `app.club_id` soit posé — c'est lui qui porte le club) + policies tenant sur INSERT/
 * UPDATE/DELETE (l'écriture, elle, exige le GUC). RlsIsolationTest découvre les deux tables
 * par leur colonne `club_id` et exige ENABLE+FORCE+policy.
 */
final class Version20260726100000 extends AbstractMigration
{
    private const TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'coach wish collection: coach_wish_campaign + coach_wish_token (+RLS).';
    }

    public function up(Schema $schema): void
    {
        $hasRole = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'app_user\'');

        // --- coach_wish_campaign (tenant, RLS FORCE classique) ---
        $this->addSql('CREATE TABLE coach_wish_campaign (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, calendar_entry_id UUID NOT NULL, deadline DATE NOT NULL, weeks JSON NOT NULL, team_ids JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_wish_campaign_entry ON coach_wish_campaign (calendar_entry_id)');

        // --- coach_wish_token (lien coach ; RLS hybride) ---
        $this->addSql('CREATE TABLE coach_wish_token (id UUID NOT NULL, token VARCHAR(64) NOT NULL, campaign_id UUID NOT NULL, coach_id UUID NOT NULL, club_id UUID NOT NULL, responded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_wish_token_value ON coach_wish_token (token)');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_wish_token_campaign_coach ON coach_wish_token (campaign_id, coach_id)');
        $this->addSql('CREATE INDEX idx_coach_wish_token_campaign ON coach_wish_token (campaign_id)');
        // FK CASCADE : supprimer la campagne ou le coach emporte le token. Ces cascades
        // s'exécutent en owner (superadmin door), hors RLS — gratuites.
        $this->addSql('ALTER TABLE coach_wish_token ADD CONSTRAINT fk_coach_wish_token_campaign FOREIGN KEY (campaign_id) REFERENCES coach_wish_campaign (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coach_wish_token ADD CONSTRAINT fk_coach_wish_token_coach FOREIGN KEY (coach_id) REFERENCES coach (id) ON DELETE CASCADE');

        if ($hasRole) {
            // campaign : policy tenant_isolation classique.
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_wish_campaign TO app_user');
            $this->addSql('ALTER TABLE public.coach_wish_campaign ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.coach_wish_campaign FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.coach_wish_campaign FOR ALL TO app_user USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));

            // token : SELECT ouvert (lookup pré-GUC — le token porte le club), écritures
            // bornées au tenant. Même hybride que club_user.
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_wish_token TO app_user');
            $this->addSql('ALTER TABLE public.coach_wish_token ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.coach_wish_token FORCE ROW LEVEL SECURITY');
            $this->addSql('CREATE POLICY coach_wish_token_read ON public.coach_wish_token FOR SELECT TO app_user USING (true)');
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation_insert ON public.coach_wish_token FOR INSERT TO app_user WITH CHECK (%s)', self::TENANT_PREDICATE));
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation_update ON public.coach_wish_token FOR UPDATE TO app_user USING (%s) WITH CHECK (%s)', self::TENANT_PREDICATE, self::TENANT_PREDICATE));
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation_delete ON public.coach_wish_token FOR DELETE TO app_user USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE emporte index, policies, GRANT et FK (token avant campaign — FK).
        $this->addSql('DROP TABLE coach_wish_token');
        $this->addSql('DROP TABLE coach_wish_campaign');
    }
}
