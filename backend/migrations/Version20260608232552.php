<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608232552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON app_user (email)');
        $this->addSql('CREATE TABLE club (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, plan_id INT DEFAULT NULL, billing_cycle VARCHAR(20) DEFAULT NULL, plan_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, generation_count_season INT NOT NULL, school_zone VARCHAR(10) DEFAULT NULL, timezone VARCHAR(64) NOT NULL, locale VARCHAR(10) NOT NULL, onboarding_completed BOOLEAN NOT NULL, ffbb_club_code VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_slug ON club (slug)');
        $this->addSql('CREATE TABLE club_user (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, user_id UUID NOT NULL, role VARCHAR(20) NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_club_user_club ON club_user (club_id)');
        $this->addSql('CREATE INDEX idx_club_user_user ON club_user (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_user_membership ON club_user (club_id, user_id)');
        $this->addSql('CREATE TABLE coach (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, max_days_override SMALLINT DEFAULT NULL, max_days_override_confirmed BOOLEAN NOT NULL, acceptable_late_minutes SMALLINT DEFAULT NULL, is_active BOOLEAN NOT NULL, parent_coach_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_coach_club_season ON coach (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_coach_parent ON coach (parent_coach_id)');
        $this->addSql('CREATE TABLE coach_player_membership (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, coach_id UUID NOT NULL, team_id UUID NOT NULL, position VARCHAR(120) DEFAULT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_coach_player_membership_club_season ON coach_player_membership (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_coach_player_membership_coach ON coach_player_membership (coach_id)');
        $this->addSql('CREATE INDEX idx_coach_player_membership_team ON coach_player_membership (team_id)');
        $this->addSql('CREATE TABLE coach_unavailability (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, coach_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, end_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_coach_unavailability_club_season ON coach_unavailability (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_coach_unavailability_coach ON coach_unavailability (coach_id)');
        $this->addSql('CREATE TABLE plan (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, name VARCHAR(120) NOT NULL, max_teams INT NOT NULL, max_venues INT NOT NULL, max_generations INT NOT NULL, monthly_price NUMERIC(10, 2) NOT NULL, annual_price NUMERIC(10, 2) NOT NULL, features JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE priority_tier (id INT NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, label VARCHAR(1) NOT NULL, name VARCHAR(120) NOT NULL, color VARCHAR(20) NOT NULL, or_tools_weight INT NOT NULL, default_min_sessions INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE schedule (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, name VARCHAR(180) NOT NULL, status VARCHAR(30) NOT NULL, score INT DEFAULT NULL, solver_seed INT NOT NULL, snapshot_hash VARCHAR(128) DEFAULT NULL, snapshot_data JSON NOT NULL, solver_version VARCHAR(80) DEFAULT NULL, constraint_version VARCHAR(80) DEFAULT NULL, score_formula_version VARCHAR(80) DEFAULT NULL, solver_timeout_seconds INT DEFAULT NULL, solver_nb_variables INT DEFAULT NULL, solver_nb_constraints INT DEFAULT NULL, solver_nb_conflicts INT DEFAULT NULL, solver_wall_time_ms INT DEFAULT NULL, pdf_export_status VARCHAR(30) DEFAULT NULL, pdf_export_url VARCHAR(2048) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_schedule_club_season ON schedule (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_schedule_status ON schedule (status)');
        $this->addSql('CREATE TABLE schedule_diagnostic (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_id UUID NOT NULL, type VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL, team_id UUID DEFAULT NULL, coach_id UUID DEFAULT NULL, venue_id UUID DEFAULT NULL, message TEXT NOT NULL, suggestions JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_schedule_diagnostic_club_season ON schedule_diagnostic (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_schedule_diagnostic_schedule ON schedule_diagnostic (schedule_id)');
        $this->addSql('CREATE INDEX idx_schedule_diagnostic_team ON schedule_diagnostic (team_id)');
        $this->addSql('CREATE INDEX idx_schedule_diagnostic_coach ON schedule_diagnostic (coach_id)');
        $this->addSql('CREATE INDEX idx_schedule_diagnostic_venue ON schedule_diagnostic (venue_id)');
        $this->addSql('CREATE TABLE schedule_slot_template (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_id UUID NOT NULL, team_id UUID NOT NULL, venue_id UUID NOT NULL, coach_id UUID DEFAULT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, duration_minutes SMALLINT NOT NULL, lock_level VARCHAR(10) NOT NULL, temporary_lock BOOLEAN NOT NULL, temporary_lock_for UUID DEFAULT NULL, temporary_min_sessions_override SMALLINT DEFAULT NULL, pending_constraint_suggestion JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_schedule_slot_template_club_season ON schedule_slot_template (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_schedule_slot_template_schedule ON schedule_slot_template (schedule_id)');
        $this->addSql('CREATE INDEX idx_schedule_slot_template_team ON schedule_slot_template (team_id)');
        $this->addSql('CREATE INDEX idx_schedule_slot_template_venue ON schedule_slot_template (venue_id)');
        $this->addSql('CREATE INDEX idx_schedule_slot_template_coach ON schedule_slot_template (coach_id)');
        $this->addSql('CREATE TABLE season (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, name VARCHAR(120) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, status VARCHAR(20) NOT NULL, export_pdf_url VARCHAR(2048) DEFAULT NULL, transition_data JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_season_club_status ON season (club_id, status)');
        $this->addSql('CREATE TABLE sport (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, icon VARCHAR(80) DEFAULT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_sport_slug ON sport (slug)');
        $this->addSql('CREATE TABLE sport_category (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID DEFAULT NULL, sport_id UUID NOT NULL, name VARCHAR(120) NOT NULL, is_custom BOOLEAN NOT NULL, age_min INT DEFAULT NULL, age_max INT DEFAULT NULL, sort_order INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_sport_category_sport ON sport_category (sport_id)');
        $this->addSql('CREATE INDEX idx_sport_category_club ON sport_category (club_id)');
        $this->addSql('CREATE TABLE team (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, sport_category_id UUID NOT NULL, priority_tier_id INT NOT NULL, name VARCHAR(180) NOT NULL, gender VARCHAR(20) DEFAULT NULL, sessions_per_week SMALLINT NOT NULL, min_sessions_override SMALLINT DEFAULT NULL, match_day SMALLINT DEFAULT NULL, forced_venue_id UUID DEFAULT NULL, is_active BOOLEAN NOT NULL, parent_team_id UUID DEFAULT NULL, ffbb_team_id VARCHAR(80) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_team_club_season ON team (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_team_sport_category ON team (sport_category_id)');
        $this->addSql('CREATE INDEX idx_team_priority_tier ON team (priority_tier_id)');
        $this->addSql('CREATE INDEX idx_team_forced_venue ON team (forced_venue_id)');
        $this->addSql('CREATE INDEX idx_team_parent ON team (parent_team_id)');
        $this->addSql('CREATE TABLE team_coach (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_id UUID NOT NULL, coach_id UUID NOT NULL, role VARCHAR(20) NOT NULL, is_required BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_team_coach_club_season ON team_coach (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_team_coach_team ON team_coach (team_id)');
        $this->addSql('CREATE INDEX idx_team_coach_coach ON team_coach (coach_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_team_coach_role ON team_coach (team_id, coach_id, role)');
        $this->addSql('CREATE TABLE team_constraint (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, team_id UUID NOT NULL, type VARCHAR(20) NOT NULL, day_of_week SMALLINT DEFAULT NULL, start_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, end_time TIME(0) WITHOUT TIME ZONE DEFAULT NULL, venue_id UUID DEFAULT NULL, reason TEXT DEFAULT NULL, created_by UUID DEFAULT NULL, source_occurrence_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_team_constraint_club_season ON team_constraint (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_team_constraint_team ON team_constraint (team_id)');
        $this->addSql('CREATE INDEX idx_team_constraint_venue ON team_constraint (venue_id)');
        $this->addSql('CREATE TABLE venue (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, name VARCHAR(180) NOT NULL, is_external BOOLEAN NOT NULL, color VARCHAR(20) DEFAULT NULL, latitude NUMERIC(10, 7) DEFAULT NULL, longitude NUMERIC(10, 7) DEFAULT NULL, source VARCHAR(20) NOT NULL, external_ref VARCHAR(180) DEFAULT NULL, is_active BOOLEAN NOT NULL, parent_venue_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_club_season ON venue (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_parent ON venue (parent_venue_id)');
        $this->addSql('CREATE TABLE venue_availability (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, start_time TIME(0) WITHOUT TIME ZONE NOT NULL, end_time TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_availability_club_season ON venue_availability (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_availability_venue ON venue_availability (venue_id)');
        $this->addSql('CREATE TABLE venue_closure (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, date_start DATE NOT NULL, date_end DATE NOT NULL, reason TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_venue_closure_club_season ON venue_closure (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_venue_closure_venue ON venue_closure (venue_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA IF NOT EXISTS app_security');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE club');
        $this->addSql('DROP TABLE club_user');
        $this->addSql('DROP TABLE coach');
        $this->addSql('DROP TABLE coach_player_membership');
        $this->addSql('DROP TABLE coach_unavailability');
        $this->addSql('DROP TABLE plan');
        $this->addSql('DROP TABLE priority_tier');
        $this->addSql('DROP TABLE schedule');
        $this->addSql('DROP TABLE schedule_diagnostic');
        $this->addSql('DROP TABLE schedule_slot_template');
        $this->addSql('DROP TABLE season');
        $this->addSql('DROP TABLE sport');
        $this->addSql('DROP TABLE sport_category');
        $this->addSql('DROP TABLE team');
        $this->addSql('DROP TABLE team_coach');
        $this->addSql('DROP TABLE team_constraint');
        $this->addSql('DROP TABLE venue');
        $this->addSql('DROP TABLE venue_availability');
        $this->addSql('DROP TABLE venue_closure');
    }
}
