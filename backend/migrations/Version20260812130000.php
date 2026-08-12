<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `schedule.manually_edited_since_generation` (bool, NOT NULL, défaut false).
 *
 * Un déplacement de créneau accepté (rail du verdict moteur) modifie le placement
 * sans recalculer le score : le score stocké devient PÉRIMÉ. Cette colonne le dit à
 * l'écran, sinon le gestionnaire lit un nombre qui ne décrit plus son planning. Elle
 * est remise à false par un import de résultat solveur (le score redevient fidèle).
 *
 * Les plannings existants sont considérés NON retouchés (false) : leur score décrit
 * bien leur dernière génération tant qu'aucun déplacement manuel n'a eu lieu.
 */
final class Version20260812130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule.manually_edited_since_generation (bool, default false) — stale-score marker after a manual move.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD manually_edited_since_generation BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP COLUMN manually_edited_since_generation');
    }
}
