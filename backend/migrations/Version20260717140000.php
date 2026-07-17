<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 lot C3 — les créneaux prêtés et les réservations s'ancrent au PLAN.
 *
 * Invariant 5 : les réglages de période s'accrochent au Plan, pas au déclencheur. Ces deux
 * calques sont des RÉPONSES — « la mairie me prête ce gymnase POUR cet ajustement », « je
 * pose cette équipe DANS ce planning » — et le découpage hebdomadaire (E1) en aura besoin :
 * deux semaines pourront avoir des créneaux prêtés différents sur le même déclencheur.
 *
 * L'ancre reste NULLABLE, et sa nullité garde son sens (inv. 6) : NULL = la structure
 * PARTAGÉE (créneau saisonnier, réservation de base), non-NULL = propre à ce plan. Surtout
 * ne PAS rattacher les lignes de base au plan SEASON : la structure d'un club n'est pas un
 * réglage de période.
 *
 * ⚠️ Les contraintes DATÉES ne bougent pas — elles restent sur `CalendarEntry`. Elles
 * décrivent le FAIT (« Barros fermé »), et le radar de conflits les lit par l'entrée pour
 * déclencher le geste « ajuster ». Les accrocher au plan les rendrait illisibles tant
 * qu'aucun plan n'existe, or le plan naît de ce geste (décision fondateur 2026-07-17,
 * l'invariant 5 corrigé en conséquence — il se contredisait avec la section « Rôle de
 * CalendarEntry » depuis la rédaction).
 */
final class Version20260717140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 lot C3: venue_training_slot et reservation passent de calendar_entry_id a schedule_plan_id (nullable, NULL = structure partagee).';
    }

    public function up(Schema $schema): void
    {
        foreach (['venue_training_slot', 'reservation'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD schedule_plan_id UUID DEFAULT NULL', $table));

            // Backfill par le déclencheur : les lignes de période prennent le plan de leur
            // entrée (1:1 aujourd'hui). Les lignes de BASE ont calendar_entry_id NULL et
            // gardent donc schedule_plan_id NULL — la jointure ne les touche pas, et c'est
            // exactement ce qu'on veut (inv. 6 : la structure partagée reste sans ancre).
            $this->addSql(\sprintf(
                'UPDATE %s o SET schedule_plan_id = p.id FROM schedule_plan p WHERE p.calendar_entry_id = o.calendar_entry_id',
                $table,
            ));

            // Filet : une ligne de PÉRIODE dont l'entrée n'a pas de plan ne serait plus
            // jamais lue (ni base — son ancre serait nulle par erreur — ni période). Elle
            // est orpheline par construction ; la retirer plutôt que la laisser se faire
            // passer pour une ligne de base et polluer le SOCLE.
            $this->addSql(\sprintf(
                'DELETE FROM %s WHERE calendar_entry_id IS NOT NULL AND schedule_plan_id IS NULL',
                $table,
            ));

            $this->addSql(\sprintf('ALTER TABLE %s DROP calendar_entry_id', $table));
        }

        // L'index de la réservation suit l'ancre (le créneau n'en avait pas sur l'entrée).
        // Pas de DROP de l'ancien : le DROP COLUMN ci-dessus a déjà emporté son index.
        $this->addSql('CREATE INDEX idx_reservation_schedule_plan ON reservation (schedule_plan_id)');
    }

    public function down(Schema $schema): void
    {
        foreach (['venue_training_slot', 'reservation'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD calendar_entry_id UUID DEFAULT NULL', $table));
            $this->addSql(\sprintf(
                'UPDATE %s o SET calendar_entry_id = p.calendar_entry_id FROM schedule_plan p WHERE p.id = o.schedule_plan_id',
                $table,
            ));
            $this->addSql(\sprintf('ALTER TABLE %s DROP schedule_plan_id', $table));
        }

        $this->addSql('DROP INDEX IF EXISTS idx_reservation_schedule_plan');
        $this->addSql('CREATE INDEX idx_reservation_calendar_entry ON reservation (calendar_entry_id)');
    }
}
