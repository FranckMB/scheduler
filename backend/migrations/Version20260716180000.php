<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 — LA BASCULE : le plan SEASON et sa version choisie deviennent LE
 * calendrier de base de la saison, et le legacy meurt dans le même commit.
 *
 * Une seule vérité : « validé » se dérive du pointeur (`schedule_plan.chosen_schedule_id`).
 * Tous les lecteurs (radar de conflits matchs, routing, mode guidé, bannière) ont
 * basculé dessus dans ce même commit — c'est la condition pour supprimer le legacy
 * sans laisser deux vérités divergentes.
 *
 * Ordre imposé :
 *  1. le NOM public passe sur le plan (dernier backfill depuis `planning_name`) ;
 *  2. les statuts legacy disparaissent : VALIDATED/ARCHIVED → COMPLETED (le solveur
 *     avait rendu sa réponse ; « choisi » est désormais porté par le pointeur seul) ;
 *  3. les colonnes legacy sont supprimées.
 *
 * `chosen_schedule_id` est déjà juste : réparé par Version20260716130000 (il avait
 * été backfillé depuis des pointeurs AUTO-assignés, pas depuis un choix).
 */
final class Version20260716180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 bascule: le nom passe sur le plan, les statuts VALIDATED/ARCHIVED et les colonnes legacy de season sont supprimés.';
    }

    public function up(Schema $schema): void
    {
        // 1. Dernier backfill du nom : `planning_name` était le nom public choisi par
        //    le gestionnaire — il vit maintenant sur le plan (inv. 12) et disparaît
        //    ci-dessous. Ne pas l'emporter perdrait le nom de chaque club.
        $this->addSql(
            'UPDATE schedule_plan p SET name = s.planning_name '
            . 'FROM season s '
            . 'WHERE p.season_id = s.id AND p.type = \'SEASON\' '
            . 'AND s.planning_name IS NOT NULL AND trim(s.planning_name) <> \'\'',
        );

        // 2. Les versions ARCHIVED n'ont pas d'équivalent dans le nouveau modèle : elles
        //    étaient les sœurs ÉCARTÉES à la validation, et valider les SUPPRIME
        //    désormais (inv. 1 — plus de filet). Les passer en COMPLETED les
        //    ressusciterait en versions normales, visibles et sélectionnables à côté de
        //    la version choisie : le sélecteur ne filtre plus ARCHIVED, puisque le statut
        //    n'existe plus. On les supprime, comme la validation l'aurait fait.
        //
        //    Aucune FK ne pend à `schedule` (que des colonnes UUID nues) : les enfants et
        //    les pointeurs se purgent à la main, enfants d'abord, sinon ils survivent
        //    orphelins en nommant une version morte.
        foreach (['constraint_conflict', 'schedule_diagnostic', 'schedule_slot_template', 'schedule_structure_snapshot', 'solver_metrics'] as $child) {
            $this->addSql(\sprintf(
                'DELETE FROM %s WHERE schedule_id IN (SELECT id FROM schedule WHERE status = \'ARCHIVED\')',
                $child,
            ));
        }
        // Les pointeurs qui nommeraient une version supprimée : la ★ (photo chargée) et
        // l'overlay actif d'une période. `chosen_schedule_id` ne peut pas viser une
        // ARCHIVED (Version20260716130000 le dérive de VALIDATED seul), mais le dépointer
        // ici coûte zéro et ferme la question.
        $this->addSql('UPDATE season SET live_context_schedule_id = NULL WHERE live_context_schedule_id IN (SELECT id FROM schedule WHERE status = \'ARCHIVED\')');
        $this->addSql('UPDATE calendar_entry SET overlay_schedule_id = NULL WHERE overlay_schedule_id IN (SELECT id FROM schedule WHERE status = \'ARCHIVED\')');
        $this->addSql('UPDATE schedule_plan SET chosen_schedule_id = NULL WHERE chosen_schedule_id IN (SELECT id FROM schedule WHERE status = \'ARCHIVED\')');
        $this->addSql('DELETE FROM schedule WHERE status = \'ARCHIVED\'');

        // VALIDATED disait « choisie » — le pointeur le dit seul désormais. La version
        // reste une résolution du solveur : elle est COMPLETED.
        $this->addSql('UPDATE schedule SET status = \'COMPLETED\' WHERE status = \'VALIDATED\'');

        // 3. Le legacy meurt.
        $this->addSql('ALTER TABLE season DROP baseline_schedule_id, DROP socle_validated_at, DROP planning_name');
    }

    public function down(Schema $schema): void
    {
        // Les colonnes reviennent VIDES : le nom vit sur le plan, et « choisi »/« écarté »
        // ne sont plus déductibles d'un statut. Un retour arrière suppose une restauration.
        $this->addSql('ALTER TABLE season ADD baseline_schedule_id UUID DEFAULT NULL, ADD socle_validated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD planning_name VARCHAR(120) DEFAULT NULL');
    }
}
