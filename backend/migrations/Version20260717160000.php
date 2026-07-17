<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 lot C4 (PR2) — `schedule.calendar_entry_id` DISPARAÎT.
 *
 * Le champ était le doublon d'ancre nullable de `schedule_plan.calendar_entry_id` : « socle
 * ou overlay ? » se lit désormais du plan (`plan.type === SEASON`, PR1), et les versions
 * d'une période se regroupent par `schedule_plan_id`. Plus aucun lecteur backend (PR1) ni
 * front (cette PR), plus aucun écrivain (le write-path pose `schedule_plan_id` à la création,
 * la période étant nommée par son plan). La colonne — et son index partiel
 * `idx_schedule_calendar_entry`, emporté par le DROP COLUMN — n'a plus d'objet.
 *
 * V0, rien en prod : coupe nette, aucune donnée à préserver. Le lien `schedule_plan_id`
 * (déjà backfillé, lot A) porte toute l'information.
 */
final class Version20260717160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 lot C4 (PR2): DROP schedule.calendar_entry_id (doublon d\'ancre — le plan porte le socle-vs-overlay).';
    }

    public function up(Schema $schema): void
    {
        // Le DROP COLUMN emporte l'index partiel idx_schedule_calendar_entry avec lui.
        $this->addSql('ALTER TABLE schedule DROP calendar_entry_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD calendar_entry_id UUID DEFAULT NULL');
        // Reconstitue l'ancre depuis le plan de chaque version (NULL pour un plan SEASON).
        $this->addSql('UPDATE schedule s SET calendar_entry_id = p.calendar_entry_id FROM schedule_plan p WHERE p.id = s.schedule_plan_id');
        $this->addSql('CREATE INDEX idx_schedule_calendar_entry ON schedule (calendar_entry_id) WHERE calendar_entry_id IS NOT NULL');
    }
}
