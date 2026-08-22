<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot PASSERELLES PR-1 — une passerelle (`team_link`) gagne son intensité CÔTÉ
 * ENTRAÎNEMENT : `training_intensity` (PREFERRED / MANDATORY). Le nom porte
 * « training » car le réglage ne gouverne QUE le solveur d'entraînement ; le
 * rail matchs reste sur sa pénalité SOFT (arbitrage fondateur n°1).
 *
 * NOT NULL DEFAULT 'PREFERRED' : l'existant devient PREFERRED, sans rupture.
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que
 * doctrine/dbal reste < 4.5 (piège documenté `.claude/rules/backend.md`).
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passerelles PR-1: team_link.training_intensity (PREFERRED/MANDATORY, NOT NULL DEFAULT PREFERRED).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_link ADD training_intensity VARCHAR(20) DEFAULT \'PREFERRED\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team_link DROP COLUMN IF EXISTS training_intensity');
    }
}
