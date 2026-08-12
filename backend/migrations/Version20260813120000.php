<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `schedule.resources_changed_since_generation` (bool, NOT NULL, défaut false).
 *
 * Une contrainte n'est pas la seule entrée du solveur. Un gymnase renommé/désactivé, un
 * coach retiré, un créneau ou une grille de période modifiés, une réservation, un override
 * de période, un tag d'équipe re-dérivé, une entrée de calendrier : autant de DONNÉES DU CLUB
 * dont un changement APRÈS la génération rend le planning PÉRIMÉ — il décrit un état antérieur
 * des données, sans que rien ne le dise. Cette colonne le dit à l'écran, dans la MÊME bannière
 * que les deux autres marqueurs de péremption.
 *
 * UN SEUL marqueur pour TOUTES ces sources (pas un booléen par source, qui serait une ferme de
 * colonnes que la bannière ne saurait plus lire). Posé à true par
 * `ResourceChangeStaleScheduleListener` (listener d'entité générique, tout writer même hors
 * API) ; remis à false par un import de résultat solveur (le planning redevient fidèle aux
 * données), au même endroit que les deux jumeaux.
 *
 * Troisième jumeau de `manually_edited_since_generation` (Version20260812130000) et
 * `constraints_changed_since_generation` (Version20260812140000).
 *
 * Les plannings existants sont considérés à jour (false) : tant qu'aucune ressource n'a changé
 * depuis, leur dernière génération décrit bien les données courantes.
 */
final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule.resources_changed_since_generation (bool, default false) — stale marker after a resource (venue/coach/slot/reservation/period/tag/calendar) change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD resources_changed_since_generation BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP COLUMN resources_changed_since_generation');
    }
}
