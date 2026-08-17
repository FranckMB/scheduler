<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bien-être PAR PÉRIODE — PR1 : `implicit_rule_setting` gagne l'ancre de portée
 * `schedule_plan_id` (ADR-0002 inv. 5).
 *
 * NULL = réglage de SAISON (base + repli des plans legacy sans copie) ; un UUID = un plan de
 * période, qui possède SA copie des 4 règles. Colonne guid NULLABLE sans FK (patron
 * `venue_training_slot`/`reservation`/`shared_training_group` : le plan supprimé emporte ses
 * lignes par la purge applicative, `OverlayManager::purgePlanAnchoredSettings`).
 *
 * L'unicité passe de `(club_id, season_id, rule_key)` à
 * `(club_id, season_id, schedule_plan_id, rule_key)` avec NULLS NOT DISTINCT (PG 16) : sans ce
 * modificateur deux réglages de SAISON (plan NULL) de la même règle seraient vus comme distincts
 * et l'unicité ne les rejetterait jamais. Précédent : `uniq_league_match_window`
 * (Version20260706250000). Écrite à la main : `make migration-diff` est inopérant tant que
 * doctrine/dbal reste < 4.5.
 */
final class Version20260817130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bien-être par période PR1: implicit_rule_setting +schedule_plan_id (nullable, index) + unicité (club, season, plan, rule) NULLS NOT DISTINCT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE implicit_rule_setting ADD schedule_plan_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_implicit_rule_plan ON implicit_rule_setting (schedule_plan_id)');

        // L'ancienne unicité (sans plan) laisse place à celle qui porte la portée. NULLS NOT
        // DISTINCT : deux réglages de SAISON (plan NULL) de la même règle DOIVENT collisionner.
        $this->addSql('DROP INDEX uniq_implicit_rule_club_season_key');
        $this->addSql('CREATE UNIQUE INDEX uniq_implicit_rule_club_season_plan_key ON implicit_rule_setting (club_id, season_id, schedule_plan_id, rule_key) NULLS NOT DISTINCT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_implicit_rule_club_season_plan_key');
        // Les copies de période doivent partir avant de restaurer l'unicité sans plan, sinon
        // deux lignes (base + période) de la même règle la violeraient.
        $this->addSql('DELETE FROM implicit_rule_setting WHERE schedule_plan_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_implicit_rule_club_season_key ON implicit_rule_setting (club_id, season_id, rule_key)');
        $this->addSql('DROP INDEX idx_implicit_rule_plan');
        $this->addSql('ALTER TABLE implicit_rule_setting DROP schedule_plan_id');
    }
}
