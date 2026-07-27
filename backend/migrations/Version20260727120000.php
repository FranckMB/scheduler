<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-30 — les ancres `schedule_plan_id` gagnent leur intégrité référentielle.
 *
 * `Reservation.schedulePlanId` et `VenueTrainingSlot.schedulePlanId` étaient des
 * colonnes `guid` NUES : aucune FK, donc supprimer un plan (`deletePeriodPlan`
 * fait un `DELETE FROM schedule_plan` sec) laissait des lignes à l'ancre
 * pendante. Elles ne sont lues nulle part — `StructureSnapshotter::loadFamily`
 * filtre `schedule_plan_id IS NULL`, donc un orphelin n'entre PAS dans le gel
 * envoyé au solveur — mais elles s'accumulent indéfiniment.
 *
 * Deux temps, et l'ordre n'est pas négociable : PostgreSQL refuse de poser une
 * FK tant qu'une ligne la viole (« Key (parent_id)=(…) is not present »). La
 * purge doit donc précéder, et être inconditionnelle : la base de dev en compte
 * zéro, ce qui ne prouve rien de la production.
 *
 * ON DELETE CASCADE, pas SET NULL : `schedule_plan_id IS NULL` signifie « ligne
 * de base, partagée par la saison » (inv. 6). Un SET NULL transformerait les
 * créneaux d'une période supprimée en créneaux PERMANENTS de la saison, que le
 * solveur prendrait au sérieux à la génération suivante — un bug silencieux, pas
 * un choix moins bon.
 *
 * Ces cascades s'exécutent en owner, hors RLS (l'intégrité référentielle
 * contourne la sécurité de ligne — vérifié : un enfant estampillé d'un autre
 * club, invisible à la session qui supprime, est bien emporté). C'est ce qui
 * garantit qu'aucun orphelin ne survit, et c'est le même raisonnement que les
 * FK de `coach_wish_token`.
 *
 * ⚠ PIÈGE — la colonne restant une `guid` nue, Doctrine ignore ces contraintes :
 * `doctrine:schema:update --complete` (et donc `migration-diff`) propose de les
 * DROPper. Ce n'est pas propre à cette migration — il propose déjà la même chose
 * pour les deux FK de `coach_wish_token`. Ne jamais committer ce DROP :
 * `SchedulePlanAnchorCascadeTest` échouerait, ce qui est précisément son rôle.
 */
final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-30: purge orphaned schedule_plan_id anchors, then add FK ON DELETE CASCADE.';
    }

    public function up(Schema $schema): void
    {
        foreach (['reservation', 'venue_training_slot'] as $table) {
            // 1. Purge — obligatoire AVANT la FK, sinon l'ALTER échoue.
            $this->addSql(\sprintf(
                'DELETE FROM %s WHERE schedule_plan_id IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM schedule_plan p WHERE p.id = %s.schedule_plan_id)',
                $table,
                $table,
            ));

            // 2. La contrainte. Colonne `guid` nue conservée (aucun `ManyToOne`) :
            //    même forme que `coach_wish_token`, le modèle s'ancre par identifiant.
            $this->addSql(\sprintf(
                'ALTER TABLE %s ADD CONSTRAINT fk_%s_schedule_plan
                 FOREIGN KEY (schedule_plan_id) REFERENCES schedule_plan (id) ON DELETE CASCADE',
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Les lignes purgées ne reviennent pas — elles étaient déjà inatteignables.
        foreach (['reservation', 'venue_training_slot'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT fk_%s_schedule_plan', $table, $table));
        }
    }
}
