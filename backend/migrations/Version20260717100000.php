<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 lot C2 — les deux jumeaux s'ancrent au PLAN.
 *
 * Invariant 5 : « les réglages de période s'accrochent au Plan (pas au déclencheur
 * calendrier) […] re-keyés `calendarEntryId` → `planId`. Chaque plan-semaine a SES
 * réglages. » `TeamPeriodOverride` et `ConstraintPeriodOverride` sont traités d'un bloc
 * (roadmap P4-12 : « les 2 jumeaux »).
 *
 * Aucun changement fonctionnel aujourd'hui : `uniq_schedule_plan_calendar_entry` impose
 * un plan par période, donc l'ancre revient au même. C'est le découpage hebdomadaire
 * (types-de-planning E1) que ce lot débloque — 2 semaines ⇒ 2 plans ⇒ 2 jeux de réglages
 * sur le MÊME déclencheur, que `calendar_entry_id` ne saurait pas distinguer.
 *
 * Le backfill est fiable pour la même raison : la correspondance période → plan est 1:1,
 * et le lot C1 garantit qu'une période génératrice porte toujours un plan (né du geste,
 * rattrapage inclus). Une ligne dont le plan resterait introuvable serait un réglage
 * orphelin d'une période sans plan — impossible depuis C1 ; le DELETE de garde la retire
 * plutôt que de faire échouer le NOT NULL.
 */
final class Version20260717100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 lot C2: team_period_override et constraint_period_override passent de calendar_entry_id a schedule_plan_id.';
    }

    public function up(Schema $schema): void
    {
        foreach (['team_period_override' => 'team_id', 'constraint_period_override' => 'constraint_id'] as $table => $other) {
            // 1. la colonne arrive, nullable le temps du backfill.
            $this->addSql(\sprintf('ALTER TABLE %s ADD schedule_plan_id UUID DEFAULT NULL', $table));

            // 2. backfill par le déclencheur (1:1 aujourd'hui).
            $this->addSql(\sprintf(
                'UPDATE %s o SET schedule_plan_id = p.id FROM schedule_plan p WHERE p.calendar_entry_id = o.calendar_entry_id',
                $table,
            ));

            // 3. filet : un réglage dont la période n'a pas de plan est orphelin par
            //    construction (il ne serait plus jamais lu). Le retirer plutôt que
            //    bloquer le NOT NULL ci-dessous.
            $this->addSql(\sprintf('DELETE FROM %s WHERE schedule_plan_id IS NULL', $table));

            $this->addSql(\sprintf('ALTER TABLE %s ALTER COLUMN schedule_plan_id SET NOT NULL', $table));

            // 4. l'unicité et l'index suivent la nouvelle ancre.
            $this->addSql(\sprintf('DROP INDEX IF EXISTS uniq_%s', $table));
            $this->addSql(\sprintf('DROP INDEX IF EXISTS idx_%s_entry', $table));
            $this->addSql(\sprintf(
                'CREATE UNIQUE INDEX uniq_%s ON %s (schedule_plan_id, %s)',
                $table,
                $table,
                $other,
            ));
            $this->addSql(\sprintf('CREATE INDEX idx_%s_plan ON %s (schedule_plan_id)', $table, $table));

            // 5. l'ancienne ancre disparaît : une seule vérité.
            $this->addSql(\sprintf('ALTER TABLE %s DROP calendar_entry_id', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['team_period_override' => 'team_id', 'constraint_period_override' => 'constraint_id'] as $table => $other) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD calendar_entry_id UUID DEFAULT NULL', $table));
            $this->addSql(\sprintf(
                'UPDATE %s o SET calendar_entry_id = p.calendar_entry_id FROM schedule_plan p WHERE p.id = o.schedule_plan_id',
                $table,
            ));
            $this->addSql(\sprintf('DELETE FROM %s WHERE calendar_entry_id IS NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER COLUMN calendar_entry_id SET NOT NULL', $table));
            $this->addSql(\sprintf('DROP INDEX IF EXISTS uniq_%s', $table));
            $this->addSql(\sprintf('DROP INDEX IF EXISTS idx_%s_plan', $table));
            $this->addSql(\sprintf('CREATE UNIQUE INDEX uniq_%s ON %s (calendar_entry_id, %s)', $table, $table, $other));
            $this->addSql(\sprintf('CREATE INDEX idx_%s_entry ON %s (calendar_entry_id)', $table, $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP schedule_plan_id', $table));
        }
    }
}
