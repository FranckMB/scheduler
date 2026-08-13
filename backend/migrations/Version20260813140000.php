<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5-10 — métriques de capacité (collecte). ADD COLUMN nullable UNIQUEMENT.
 *
 * `schedule` gagne `queued_at` (mise en file) et `solve_started_at` (flush
 * GENERATING) : l'attente en file et l'instant de solve deviennent mesurables.
 *
 * `solver_metrics` (append-only) reçoit la copie de ces deux instants + les
 * métriques de capacité renvoyées par l'engine (contrat 2.6, champs optionnels) :
 * taille du payload, temps mur TOTAL du solve (pas la seule dernière phase),
 * temps CPU, workers/budget réellement posés, détail de statut phase 1, pic RSS
 * et RSS de départ, et l'attente moteur. `nb_conflicts` existait déjà.
 *
 * Historique existant : colonnes null (pas de backfill possible ni utile).
 */
final class Version20260813140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P5-10 capacity metrics: schedule +queued_at/solve_started_at; solver_metrics +11 nullable capacity columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD queued_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule ADD solve_started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql('ALTER TABLE solver_metrics ADD queued_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD solve_started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD payload_bytes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD total_wall_time_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD cpu_time_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD workers INT DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD budget_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD solver_status_detail VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD peak_rss_mb DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD rss_before_mb DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE solver_metrics ADD engine_wait_ms INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP COLUMN queued_at');
        $this->addSql('ALTER TABLE schedule DROP COLUMN solve_started_at');

        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN queued_at');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN solve_started_at');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN payload_bytes');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN total_wall_time_ms');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN cpu_time_ms');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN workers');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN budget_seconds');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN solver_status_detail');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN peak_rss_mb');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN rss_before_mb');
        $this->addSql('ALTER TABLE solver_metrics DROP COLUMN engine_wait_ms');
    }
}
