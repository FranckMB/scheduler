<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 lot D (a) — `schedule.schedule_plan_id` + `version_number` deviennent NOT NULL.
 *
 * Une VERSION SANS PLAN n'existe pas (ruling fondateur) : depuis PR2, toute création lie et
 * numérote la version (le POST nomme le plan, le regenerate reprend celui de la source). Les
 * deux colonnes, laissées nullable pendant la transition additive (lot A), n'ont plus de raison
 * de l'être — la contrainte DB scelle l'invariant : plus aucune version orpheline persistable.
 *
 * V0, rien en prod ; 0 orphelin en base (backfill A + naissance C1 + liaison PR2). La purge
 * ci-dessous est un FILET : une version résiduelle non liée (fenêtre de reset concurrent,
 * note B1) est supprimée avec tous ses artefacts SANS FK (miroir SQL d'OverlayManager::
 * purgeArtifacts) avant le SET NOT NULL, qui échouerait sinon.
 *
 * `overlayScheduleId` n'est PAS touché ici (lot D-b) : sa purge se limite à nuller le pointeur
 * inverse des entrées qui viseraient un orphelin supprimé.
 */
final class Version20260717180000 extends AbstractMigration
{
    private const ORPHANS = '(SELECT id FROM schedule WHERE schedule_plan_id IS NULL OR version_number IS NULL)';

    public function getDescription(): string
    {
        return 'ADR-0002 lot D(a): schedule.schedule_plan_id + version_number NOT NULL (purge defensive des orphelins d\'abord).';
    }

    public function up(Schema $schema): void
    {
        // 1) Purge défensive des orphelins + de leurs artefacts (aucune FK cascade). Attendu : 0.
        foreach (['schedule_slot_template', 'schedule_diagnostic', 'constraint_conflict', 'schedule_structure_snapshot', 'solver_metrics'] as $table) {
            $this->addSql(\sprintf('DELETE FROM %s WHERE schedule_id IN %s', $table, self::ORPHANS));
        }
        // Pointeurs vers un orphelin : les relâcher avant de le supprimer (colonnes guid nues, sans FK).
        $this->addSql('UPDATE calendar_entry SET overlay_schedule_id = NULL WHERE overlay_schedule_id IN ' . self::ORPHANS);
        $this->addSql('UPDATE season SET live_context_schedule_id = NULL WHERE live_context_schedule_id IN ' . self::ORPHANS);
        $this->addSql('UPDATE schedule_plan SET chosen_schedule_id = NULL WHERE chosen_schedule_id IN ' . self::ORPHANS);
        $this->addSql('DELETE FROM schedule WHERE schedule_plan_id IS NULL OR version_number IS NULL');

        // 2) Sceller l'invariant.
        $this->addSql('ALTER TABLE schedule ALTER COLUMN schedule_plan_id SET NOT NULL');
        $this->addSql('ALTER TABLE schedule ALTER COLUMN version_number SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Réversibilité du schéma uniquement — les orphelins purgés ne se restaurent pas (V0).
        $this->addSql('ALTER TABLE schedule ALTER COLUMN schedule_plan_id DROP NOT NULL');
        $this->addSql('ALTER TABLE schedule ALTER COLUMN version_number DROP NOT NULL');
    }
}
