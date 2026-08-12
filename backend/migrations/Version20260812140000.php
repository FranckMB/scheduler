<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `schedule.constraints_changed_since_generation` (bool, NOT NULL, défaut false).
 *
 * Une contrainte modifiée (créée, changée, supprimée) APRÈS la génération d'un planning
 * rend ce planning PÉRIMÉ : il décrit un état antérieur des règles, sans que rien ne le
 * dise. Cette colonne le dit à l'écran. Posée à true par le listener d'entité sur
 * `Constraint` (tous les plannings COMPLETED du club+saison de la contrainte) ; remise à
 * false par un import de résultat solveur (le planning redevient fidèle aux données).
 *
 * Jumelle de `manually_edited_since_generation` (Version20260812130000), autre déclencheur.
 *
 * Les plannings existants sont considérés à jour (false) : tant qu'aucune contrainte n'a
 * changé depuis, leur dernière génération décrit bien les règles courantes.
 */
final class Version20260812140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule.constraints_changed_since_generation (bool, default false) — stale marker after a constraint change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD constraints_changed_since_generation BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP COLUMN constraints_changed_since_generation');
    }
}
