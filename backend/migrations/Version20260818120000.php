<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'indisponibilité de gymnase devient INFORMATIVE — la coche du plan fait foi (décision
 * fondateur 2026-08-18). `venue_period_override` gagne le masque manuel tri-état et son
 * `mode` cesse d'être obligatoire.
 *
 *  - `day_overrides` (JSON nullable) : masque SPARSE jour ISO (1..7) → OPEN|CLOSED, un jour
 *    absent hérite du défaut. Il s'ajoute au défaut dérivé de l'indisponibilité déclarée.
 *  - `mode` devient NULLABLE (DROP NOT NULL) : NULL = hériter — une ligne peut désormais
 *    exister pour son SEUL masque, sans imposer DISABLED/BLANK.
 *
 * Additive et rétro-compatible : les lignes existantes gardent leur `mode` non nul et un
 * `day_overrides` NULL (= aucun jour forcé). L'unicité `(schedule_plan_id, venue_id)` ne
 * bouge pas. Écrite à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5.
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indispo gymnase informative: venue_period_override +day_overrides (json nullable), mode devient nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_period_override ADD day_overrides JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE venue_period_override ALTER mode DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Les lignes nées SANS mode (masque seul) doivent partir avant de restaurer le NOT NULL.
        $this->addSql('DELETE FROM venue_period_override WHERE mode IS NULL');
        $this->addSql('ALTER TABLE venue_period_override ALTER mode SET NOT NULL');
        $this->addSql('ALTER TABLE venue_period_override DROP day_overrides');
    }
}
