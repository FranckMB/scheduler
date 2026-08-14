<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-17 — libellé de groupe (« CEC3 ») sur un créneau d'entraînement. ADD COLUMN nullable UNIQUEMENT.
 *
 * `venue_training_slot` gagne `group_label` : un libellé libre (≤ 40) posé sur un créneau
 * mutualisé (capacité ≥ 2) pour DIRE que deux équipes s'entraînent ensemble. Esthétique pur —
 * le solveur ne le consomme pas (le payload envoyé à l'engine ne bouge pas), il ne sert qu'à
 * l'affichage et aux exports. NULL = créneau sans libellé (le cas de l'immense majorité).
 */
final class Version20260814120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-17 group label: venue_training_slot +group_label (nullable VARCHAR(40)).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_training_slot ADD group_label VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_training_slot DROP COLUMN group_label');
    }
}
