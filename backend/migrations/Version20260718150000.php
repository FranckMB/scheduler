<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot SA2-stats — stats d'usage superadmin (décisions fondateur 2026-07-18).
 *
 * 1. `solver_metrics` gagne ses dimensions d'analyse dénormalisées à la capture :
 *    `plan_type` (SEASON/CLOSURE/HOLIDAY), `nb_teams`, `nb_venues` — la télémétrie
 *    devient APPEND-ONLY (plus purgée avec les versions ni au reset de saison), donc
 *    elle doit rester lisible sans joindre des lignes potentiellement supprimées.
 *    Historique existant : colonnes null (pas de backfill possible ni utile).
 * 2. `schedule_plan.first_chosen_at` : instant de la PREMIÈRE validation (posé une
 *    fois par choose(), jamais effacé) → stat « temps de clôture » (création → 1re
 *    validation). BACKFILL des plans DÉJÀ validés (chosen_schedule_id posé) avec leur
 *    `updated_at` — approximation assumée (la vraie date du geste n'existe pas), mais
 *    sans elle une simple REVALIDATION post-déploiement d'un plan legacy poserait
 *    first_chosen_at = now() et compterait des mois de « temps de clôture » fantômes
 *    dans les percentiles (cohorte V0 minuscule, biais borné ; le poison, lui, ne l'est pas).
 */
final class Version20260718150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SA2-stats: solver_metrics +plan_type/nb_teams/nb_venues (append-only), schedule_plan.first_chosen_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE solver_metrics ADD plan_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD nb_teams INT DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD nb_venues INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_solver_metrics_plan_type_created ON solver_metrics (plan_type, created_at)');
        $this->addSql('ALTER TABLE schedule_plan ADD first_chosen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Backfill des plans déjà validés (cf. docblock) — updated_at ≈ dernier geste, jamais pire
        // qu'un futur now() de revalidation.
        $this->addSql('UPDATE schedule_plan SET first_chosen_at = updated_at WHERE chosen_schedule_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_solver_metrics_plan_type_created');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN plan_type');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN nb_teams');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN nb_venues');
        $this->addSql('ALTER TABLE schedule_plan DROP COLUMN first_chosen_at');
    }
}
