<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-34 — les ancres `schedule_plan_id` des trois `*PeriodOverride` gagnent leur FK.
 *
 * Suite directe de P4-30 (`reservation`, `venue_training_slot`) : même défaut, même
 * remède. L'API acceptait n'importe quel uuid en ancre — la ligne s'écrivait, rattachée
 * à une période inexistante : invisible à l'écran, jamais lue par une génération,
 * impossible à retirer par l'UI.
 *
 * `ON DELETE CASCADE` est cohérent avec le code : `OverlayManager::purgePlanAnchoredSettings`
 * supprime DÉJÀ ces trois tables (plus les deux de P4-30) à la mort d'un plan. La FK est
 * le filet, pas le mécanisme.
 *
 * ⚠ `schedule` est VOLONTAIREMENT EXCLU, contre la lettre de la ligne roadmap :
 *  1. il est déjà protégé, et mieux — `ScheduleStateProcessor` exige que le plan existe
 *     ET soit du bon club ET de la bonne saison (422 sinon) ;
 *  2. une cascade y serait NUISIBLE : aucune table ne référence `schedule` par FK, ses
 *     enfants (créneaux générés, diagnostics) sont nettoyés par le CODE
 *     (`OverlayManager::purgeArtifacts`). Une suppression en cascade côté base
 *     court-circuiterait ce nettoyage et laisserait exactement les orphelins que cette
 *     migration existe pour supprimer.
 */
final class Version20260807120000 extends AbstractMigration
{
    /** @var list<string> */
    private const array TABLES = ['venue_period_override', 'team_period_override', 'constraint_period_override'];

    public function getDescription(): string
    {
        return 'P4-34: purge orphaned schedule_plan_id anchors on *PeriodOverride, then add FK ON DELETE CASCADE.';
    }

    public function up(Schema $schema): void
    {
        // Ne jamais attendre un verrou indéfiniment : les migrations tournent contre une
        // base VIVANTE. Un worker en pleine génération tient une transaction sur
        // `schedule_plan` ; sans borne, l'ALTER se met en file ET toute écriture
        // ultérieure derrière lui. Mieux vaut échouer le déploiement (patron P4-30).
        $this->addSql('SET lock_timeout = \'5s\'');

        foreach (self::TABLES as $table) {
            // 1. Purge — obligatoire AVANT la FK, sinon l'ALTER échoue. DEUX familles :
            //    le plan ABSENT, et le plan d'un AUTRE club. La seconde existe parce que
            //    l'API n'a jamais contrôlé ni l'existence ni l'appartenance ; la laisser
            //    serait pire qu'avant, la cascade étant exemptée de RLS (la suppression
            //    d'une période emporterait la ligne d'un autre tenant).
            $this->addSql(\sprintf(
                'DELETE FROM %1$s WHERE NOT EXISTS (
                     SELECT 1 FROM schedule_plan p
                     WHERE p.id = %1$s.schedule_plan_id AND p.club_id = %1$s.club_id
                 )',
                $table,
            ));

            // 2. La contrainte en DEUX temps : `NOT VALID` la pose sans scanner la table
            //    (verrou bref), `VALIDATE` vérifie ensuite sous un verrou plus faible qui
            //    ne bloque ni les lectures ni les écritures.
            $this->addSql(\sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT fk_%1$s_schedule_plan
                 FOREIGN KEY (schedule_plan_id) REFERENCES schedule_plan (id) ON DELETE CASCADE NOT VALID',
                $table,
            ));
            $this->addSql(\sprintf('ALTER TABLE %1$s VALIDATE CONSTRAINT fk_%1$s_schedule_plan', $table));
        }
    }

    public function down(Schema $schema): void
    {
        // Les lignes purgées ne reviennent pas — elles étaient déjà inatteignables.
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %1$s DROP CONSTRAINT fk_%1$s_schedule_plan', $table));
        }
    }
}
