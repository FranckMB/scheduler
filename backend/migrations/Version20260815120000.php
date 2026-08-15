<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Volet A « tags » — le tag système d'âge « adulte » s'appelle enfin ADULTE.
 *
 * Le tag `SENIOR` signifiait « + de 18 » (`ageMin >= 19`) et s'affichait « Adulte » : le nom
 * mentait, et la vraie notion « senior » (+ de 22) n'existait pas. On renomme donc l'existant
 * `SENIOR` → `ADULTE` pour TOUS les clubs (système ou non — le nom est unique par club, il
 * DÉSIGNE le groupe), et on repointe les contraintes qui le ciblent. Les assignations suivent
 * le `tag_id` INCHANGÉ (rename, pas recréation) → sémantique préservée, aucun planning à périmer.
 *
 * Les NOUVEAUX tags système `SENIOR` (+ de 22) et `COMPETITION` (engagement en compétition)
 * ne sont PAS semés ici : ils naissent à la prochaine dérivation (écriture d'équipe) ou par la
 * commande `app:team-tags:resync` sur un club existant — foyer unique de la règle
 * (`TeamTagService`), jamais un backfill SQL qui la recopierait.
 *
 * ⚠ CONFLICT-SAFE : `uniq_team_tag_club_name` interdit deux `ADULTE` dans un club. Un club qui
 * possède DÉJÀ un tag `ADULTE` (personnalisé) ne peut pas voir son `SENIOR` renommé — on bascule
 * alors ses assignations vers l'`ADULTE` en place (sans doublonner) puis on supprime la ligne
 * `SENIOR` devenue vide. Migration exécutée sous le rôle propriétaire (porte `admin_all`), qui
 * voit toutes les lignes de tous les clubs.
 */
final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename team_tag SENIOR → ADULTE (all clubs, conflict-safe) + repoint constraint targetTag SENIOR → ADULTE.';
    }

    public function up(Schema $schema): void
    {
        // --- Cas homonyme : un club a DÉJÀ un tag « ADULTE ». On fusionne le « SENIOR » dedans.
        // (i) Déplacer les assignations qui ne collisionnent pas avec une assignation ADULTE
        //     déjà présente pour la même équipe/saison.
        $this->addSql(
            'UPDATE team_tag_assignment a
             SET tag_id = adulte.id
             FROM team_tag senior
             JOIN team_tag adulte ON adulte.club_id = senior.club_id AND adulte.name = \'ADULTE\'
             WHERE a.tag_id = senior.id
               AND senior.name = \'SENIOR\'
               AND NOT EXISTS (
                   SELECT 1 FROM team_tag_assignment b
                   WHERE b.team_id = a.team_id AND b.season_id = a.season_id AND b.tag_id = adulte.id
               )',
        );
        // (ii) Supprimer les assignations SENIOR restées (l'équipe portait déjà ADULTE) : elles
        //      feraient doublon, la ligne ADULTE couvre déjà l'équipe.
        $this->addSql(
            'DELETE FROM team_tag_assignment a
             USING team_tag senior
             WHERE a.tag_id = senior.id
               AND senior.name = \'SENIOR\'
               AND EXISTS (SELECT 1 FROM team_tag adulte WHERE adulte.club_id = senior.club_id AND adulte.name = \'ADULTE\')',
        );
        // (iii) Supprimer la ligne SENIOR désormais orpheline (uniquement dans les clubs qui ont ADULTE).
        $this->addSql(
            'DELETE FROM team_tag senior
             WHERE senior.name = \'SENIOR\'
               AND EXISTS (SELECT 1 FROM team_tag adulte WHERE adulte.club_id = senior.club_id AND adulte.name = \'ADULTE\')',
        );

        // --- Cas normal : renommer les SENIOR restants (clubs SANS ADULTE préexistant).
        $this->addSql('UPDATE team_tag SET name = \'ADULTE\' WHERE name = \'SENIOR\'');

        // --- Contraintes ciblant le tag renommé (permanentes ET datées : une seule table).
        // `config` est de type JSON (pas JSONB) → cast pour `jsonb_set`, puis retour en JSON.
        $this->addSql(
            'UPDATE "constraint"
             SET config = jsonb_set(config::jsonb, \'{targetTag}\', \'"ADULTE"\'::jsonb)::json
             WHERE config->>\'targetTag\' = \'SENIOR\'',
        );
    }

    public function down(Schema $schema): void
    {
        // Retour symétrique : ADULTE redevient SENIOR. Irréversible sur le cas homonyme fusionné
        // (la ligne SENIOR supprimée ne se reconstruit pas) — best-effort, comme tout down de DML.
        $this->addSql('UPDATE team_tag SET name = \'SENIOR\' WHERE name = \'ADULTE\' AND is_system = true');
        $this->addSql(
            'UPDATE "constraint"
             SET config = jsonb_set(config::jsonb, \'{targetTag}\', \'"SENIOR"\'::jsonb)::json
             WHERE config->>\'targetTag\' = \'ADULTE\'',
        );
    }
}
