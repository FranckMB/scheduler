<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-3 PR A — socle d'offres par statut (bridage freemium Découverte).
 *
 * `subscription_plan` : gagne une clé stable `code` (l'attribution superadmin cible
 * un plan par code, jamais par nom ou UUID), PERD `monthly_price`/`annual_price`
 * (aucun montant nulle part — décision fondateur 2026-08-09, « sur demande »). Les
 * 6 offres sont seedées ICI avec des UUID FIXES (idempotent sur l'id) — convention
 * 0 = illimité sur les trois caps ; `max_generations` de Découverte = le pool de
 * 10 crédits de sortie.
 *
 * `club.plan_id` : `?int` cassé (il faisait face au guid de subscription_plan) →
 * vraie FK UUID nullable (null = Découverte, le défaut). Aucune offre n'a jamais
 * été attribuée (0 ligne non-null vérifiée en dev) → DROP + re-ADD en UUID plutôt
 * qu'un cast impossible int→uuid. Gagne aussi `output_credits_used` (compteur de
 * crédits de sortie, consommé par l'enforcement PR B).
 */
final class Version20260810120000 extends AbstractMigration
{
    /** Fixed offer UUIDs — referenced by the seed here and stable for attribution. */
    private const array PLANS = [
        // id, code, name, max_teams, max_venues, max_generations
        ['00000000-0000-4000-8000-000000000001', 'decouverte', 'Découverte', 0, 0, 10],
        ['00000000-0000-4000-8000-000000000002', 'essentiel', 'Essentiel', 20, 0, 0],
        ['00000000-0000-4000-8000-000000000003', 'club', 'Club', 30, 0, 0],
        ['00000000-0000-4000-8000-000000000004', 'grand-club', 'Grand club', 50, 0, 0],
        ['00000000-0000-4000-8000-000000000005', 'sans-limite', 'Sans limite', 0, 0, 0],
        ['00000000-0000-4000-8000-000000000006', 'beta', 'Bêta', 0, 0, 0],
    ];

    public function getDescription(): string
    {
        return 'P1-3 PR A : socle d\'offres par statut — code + drop prix + seed 6 offres, club.plan_id en FK UUID + output_credits_used';
    }

    public function up(Schema $schema): void
    {
        // --- subscription_plan : code stable, plus de montants ---
        $this->addSql('ALTER TABLE subscription_plan ADD code VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_plan DROP monthly_price');
        $this->addSql('ALTER TABLE subscription_plan DROP annual_price');

        // Seed idempotent des 6 offres (UUID fixes). features = tableau vide.
        foreach (self::PLANS as [$id, $code, $name, $maxTeams, $maxVenues, $maxGenerations]) {
            $this->addSql(
                'INSERT INTO subscription_plan '
                . '(id, version, created_at, updated_at, code, name, max_teams, max_venues, max_generations, features) '
                . 'VALUES (:id, 1, NOW(), NOW(), :code, :name, :maxTeams, :maxVenues, :maxGenerations, :features) '
                . 'ON CONFLICT (id) DO NOTHING',
                [
                    'id' => $id,
                    'code' => $code,
                    'name' => $name,
                    'maxTeams' => $maxTeams,
                    'maxVenues' => $maxVenues,
                    'maxGenerations' => $maxGenerations,
                    'features' => '[]',
                ],
            );
        }

        // code peuplé sur chaque ligne → NOT NULL + unique.
        $this->addSql('ALTER TABLE subscription_plan ALTER COLUMN code SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_plan_code ON subscription_plan (code)');

        // --- club : plan_id INT (null partout) → FK UUID, + crédits de sortie ---
        $this->addSql('ALTER TABLE club DROP COLUMN plan_id');
        $this->addSql('ALTER TABLE club ADD plan_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD output_credits_used INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club ADD CONSTRAINT fk_club_plan FOREIGN KEY (plan_id) REFERENCES subscription_plan (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_club_plan_id ON club (plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP CONSTRAINT fk_club_plan');
        $this->addSql('DROP INDEX idx_club_plan_id');
        $this->addSql('ALTER TABLE club DROP COLUMN output_credits_used');
        $this->addSql('ALTER TABLE club DROP COLUMN plan_id');
        $this->addSql('ALTER TABLE club ADD plan_id INT DEFAULT NULL');

        $this->addSql('DELETE FROM subscription_plan WHERE code IN (\'decouverte\', \'essentiel\', \'club\', \'grand-club\', \'sans-limite\', \'beta\')');
        $this->addSql('DROP INDEX uniq_subscription_plan_code');
        $this->addSql('ALTER TABLE subscription_plan DROP code');
        $this->addSql('ALTER TABLE subscription_plan ADD monthly_price NUMERIC(10, 2) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE subscription_plan ADD annual_price NUMERIC(10, 2) DEFAULT 0 NOT NULL');
    }
}
