<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-99 PR 2 — le diagnostic `session_below_effective_min` persiste sa CAUSE mesurée.
 *
 * Deux colonnes additives sur `schedule_diagnostic` (contrat 2.8, causes émises par l'engine
 * en PR 1) :
 *   - `causes` JSON NOT NULL : la liste `{kind, constraintId, label, count}` des règles qui ont
 *     fermé des créneaux candidats. Ajoutée AVEC DEFAULT '[]' pour backfiller les diagnostics
 *     déjà en base (le club pilote en a), puis DROP DEFAULT pour coller à la métadonnée ORM
 *     (le type `json` ne déclare pas de défaut SQL — patron `is_employee`/`version`).
 *   - `open_candidates` SMALLINT nullable : le nombre de créneaux restés OUVERTS (rien ne les a
 *     fermés). NULL = non mesuré, 0 = aucun resté ouvert — la distinction porte du sens, d'où le
 *     nullable et non un défaut 0.
 *
 * Tout autre type de diagnostic laisse `causes` = [] et `open_candidates` = NULL — additif pur,
 * aucune donnée existante altérée.
 */
final class Version20260815130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-99: schedule_diagnostic +causes (JSON, session cause) +open_candidates (smallint nullable).';
    }

    public function up(Schema $schema): void
    {
        // Backfill des lignes existantes via DEFAULT, puis on le retire : le type ORM `json`
        // n'émet pas de défaut SQL, DROP DEFAULT garde le schéma en phase avec la métadonnée.
        $this->addSql('ALTER TABLE schedule_diagnostic ADD causes JSON NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE schedule_diagnostic ALTER COLUMN causes DROP DEFAULT');
        $this->addSql('ALTER TABLE schedule_diagnostic ADD open_candidates SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_diagnostic DROP COLUMN causes');
        $this->addSql('ALTER TABLE schedule_diagnostic DROP COLUMN open_candidates');
    }
}
