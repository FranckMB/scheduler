<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 lot C (C1) — LE PLAN NAÎT DU GESTE, et le flag de seed le suit.
 *
 * Décision fondateur (2026-07-17) : un plan naît en réponse à un événement du
 * calendrier. Le plan SEASON naît avec la saison ; le plan CLOSURE/HOLIDAY naît au
 * geste « ajuster une période de vacances / un souci du calendrier » — c'est-à-dire
 * à la création du `CalendarEntry`, et c'est la SEULE façon de le créer. Le lot A
 * le faisait apparaître à la première version : trop tard, puisque les réglages de
 * la période (inv. 5) se saisissent AVANT toute génération et doivent s'accrocher à
 * un plan qui existe déjà.
 *
 * `team_selection_initialized` passe donc de `calendar_entry` à `schedule_plan` :
 * c'est une propriété de la RÉPONSE (le plan), pas du FAIT (l'événement calendrier).
 *
 * Ordre imposé :
 *  1. la colonne arrive sur `schedule_plan` ;
 *  2. les périodes existantes sans plan en reçoivent un (le code ne les créera plus
 *     après coup : `linkSchedule` ne fait plus que CHERCHER) ;
 *  3. la valeur du flag est reportée sur le plan AVANT que la colonne source parte ;
 *  4. la colonne quitte `calendar_entry`.
 *
 * L'étape 2 est protégée par `uniq_schedule_plan_calendar_entry` (un plan par
 * entrée, garanti en base) ; seules les entrées `closure`/`holiday` en reçoivent un
 * — `cutoff`/`mutualisation` restent des rappels calendrier sans plan (inv. 9).
 */
final class Version20260717020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 lot C1: team_selection_initialized passe de calendar_entry a schedule_plan; les periodes sans plan en recoivent un.';
    }

    public function up(Schema $schema): void
    {
        // 1. Le flag arrive sur le plan.
        $this->addSql('ALTER TABLE schedule_plan ADD team_selection_initialized BOOLEAN DEFAULT false NOT NULL');

        // 2. Rattrapage : toute période générante encore sans plan en reçoit un. Après ce
        //    lot, plus aucun code ne crée un plan de période a posteriori — une entrée
        //    orpheline resterait donc inconfigurable pour toujours. Le nom reprend le
        //    titre de l'entrée, comme le fait `ensurePeriodPlanId` (les noms par défaut
        //    de l'inv. 12 sont un autre lot : P2-5/E6).
        $this->addSql(
            'INSERT INTO schedule_plan '
            . '(id, version, created_at, updated_at, club_id, season_id, type, name, start_date, end_date, calendar_entry_id, last_version_number, team_selection_initialized) '
            . 'SELECT gen_random_uuid(), 1, NOW(), NOW(), ce.club_id, ce.season_id, '
            . 'CASE ce.period_type WHEN \'closure\' THEN \'CLOSURE\' ELSE \'HOLIDAY\' END, '
            . 'ce.title, ce.start_date, ce.end_date, ce.id, 0, false '
            . 'FROM calendar_entry ce '
            . 'WHERE ce.period_type IN (\'closure\', \'holiday\') '
            . 'AND NOT EXISTS (SELECT 1 FROM schedule_plan p WHERE p.calendar_entry_id = ce.id)',
        );

        // 3. Report de la valeur AVANT la suppression de la source — sinon chaque période
        //    déjà configurée serait re-seedée par le wizard au prochain chargement.
        $this->addSql(
            'UPDATE schedule_plan p SET team_selection_initialized = ce.team_selection_initialized '
            . 'FROM calendar_entry ce WHERE p.calendar_entry_id = ce.id',
        );

        // 4. La source disparaît : une seule vérité.
        $this->addSql('ALTER TABLE calendar_entry DROP team_selection_initialized');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry ADD team_selection_initialized BOOLEAN DEFAULT false NOT NULL');
        $this->addSql(
            'UPDATE calendar_entry ce SET team_selection_initialized = p.team_selection_initialized '
            . 'FROM schedule_plan p WHERE p.calendar_entry_id = ce.id',
        );
        $this->addSql('ALTER TABLE schedule_plan DROP team_selection_initialized');
        // Les plans créés par le rattrapage ne sont PAS supprimés : ils sont corrects
        // dans les deux modèles (le lot A les aurait créés à la première génération).
    }
}
