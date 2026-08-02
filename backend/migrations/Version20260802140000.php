<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-64 — `team_tag` n'avait pas d'index unique sur `(club_id, name)`.
 *
 * `Version20260615010708` ne pose que `idx_team_tag_club`. Or
 * `TeamTagService::getOrCreateSystemTags` lit « ce tag manque » puis l'insère, sans verrou :
 * deux écritures de `Team` CONCURRENTES sur le même club insèrent le tag DEUX fois.
 *
 * Le dégât n'est pas le doublon en soi, c'est ce qu'il fait ensuite : `TeamTagResolver`
 * résout par `findOneBy(['name', 'clubId'])` et en choisit **une** arbitrairement, tandis que
 * les assignations se sont réparties entre les deux lignes. Une contrainte ciblant ce tag
 * n'atteint alors **qu'une partie des équipes, sans erreur ni warning** — le tag *est*
 * trouvé. C'est le pire mode de panne : silencieux et partiel.
 *
 * La fenêtre préexistait mais restait fermée en pratique (la boucle était inerte pour tout
 * club déjà semé) ; P4-42, en ajoutant le tag BABY, l'a rouverte **une fois pour chaque
 * club**, et P4-63 en ajoutera deux de plus. D'où cette migration AVANT lui.
 *
 * ⚠ Déduplication d'abord, index ensuite — sinon l'index refuse de se créer sur une base qui
 * porte déjà des doublons. On garde la ligne la PLUS ANCIENNE (`created_at`, `id` en
 * départage pour être déterministe) et on **réaffecte ses assignations** avant de supprimer
 * les autres : supprimer d'abord perdrait les affectations portées par les doublons.
 *
 * ⚠ Rétro-compatibilité (convention `docs/ops/deploy.md`) — à lire en entier, la première
 * rédaction était incomplète sur le MODE d'échec (revue #356). Le deploy migre AVANT de
 * basculer les conteneurs : pendant quelques secondes, l'ANCIENNE release tourne sur le
 * nouveau schéma. Aucune LECTURE ne casse. La seule écriture que l'index refuse est
 * l'insertion en double que ce lot supprime — mais l'ancienne release l'émet via
 * `persist()` + `flush()`, donc la violation **ferme l'EntityManager** et fait échouer TOUTE
 * la requête, pas seulement l'insertion du tag : le gestionnaire prend un 500 et son équipe
 * n'est pas créée. Fenêtre de quelques secondes, sur le seul chemin « un tag système manque
 * encore à ce club » — mais elle existe et doit être écrite.
 *
 * ⚠ Second effet de la même fenêtre : l'étape 3 supprime des lignes `team_tag` pendant que
 * l'ancienne release sert, et `team_tag_assignment.tag_id` ne porte **aucune clé étrangère**
 * (`Version20260615010708` ne crée que des index). Une assignation écrite en vol vers un tag
 * doublon que l'étape 3 vient de supprimer resterait donc orpheline, sans que la base s'y
 * oppose. Poser cette FK est une dette à part, hors de ce lot.
 */
final class Version20260802140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        // Description ÉLARGIE (revue #356) : elle annonçait « dédoublonne team_tag » alors que
        // l'étape 2 dédoublonne aussi `team_tag_assignment` sur TOUTE la table. C'est ce que
        // lit l'opérateur avant de jouer la migration en production ; elle doit dire ce que la
        // migration fait, pas ce qu'on aimerait qu'elle fasse.
        return 'P4-64 : dédoublonne team_tag (club_id, name) EN RÉAFFECTANT ses assignations, '
            . 'dédoublonne team_tag_assignment (team_id, tag_id, season_id) SUR TOUTE LA TABLE, '
            . 'puis pose l\'index unique uniq_team_tag_club_name. Irréversible.';
    }

    public function up(Schema $schema): void
    {
        // 1. Réaffecter les assignations des doublons vers la ligne gardée (la plus ancienne
        //    de chaque groupe). Fait AVANT la suppression : l'inverse perdrait ces lignes.
        $this->addSql(<<<'SQL'
            WITH gardee AS (
                SELECT DISTINCT ON (club_id, name) id, club_id, name
                FROM team_tag
                ORDER BY club_id, name, created_at, id
            )
            UPDATE team_tag_assignment a
            SET tag_id = g.id
            FROM team_tag doublon
            JOIN gardee g ON g.club_id = doublon.club_id AND g.name = doublon.name
            WHERE a.tag_id = doublon.id AND doublon.id <> g.id
            SQL);

        // 2. Une équipe pouvait porter le MÊME tag deux fois (une assignation par doublon) :
        //    la réaffectation ci-dessus les a rendues identiques. On ne garde qu'une ligne
        //    par (team_id, tag_id, season_id), sinon le payload du solveur porterait deux
        //    fois le même tag pour une équipe.
        //
        //    ⚠ PORTÉE : toute la table, délibérément (arbitrage fondateur, revue #356). Le
        //    round 1 avait relevé, à juste titre, que le rayon d'action dépassait ce que le
        //    nom annonçait. Le resserrer aux seuls tags dupliqués a supprimé un nettoyage
        //    LÉGITIME : un triplet en double sur un tag qui n'a jamais eu de doublon (écriture
        //    concurrente antérieure) est exactement le même préjudice — le payload porterait
        //    deux fois le même tag pour une équipe. On garde donc la portée large et c'est la
        //    DESCRIPTION qui a été élargie, pas la requête qui a été rétrécie.
        $this->addSql(<<<'SQL'
            DELETE FROM team_tag_assignment a
            USING team_tag_assignment plus_ancienne
            WHERE a.team_id = plus_ancienne.team_id
              AND a.tag_id = plus_ancienne.tag_id
              AND a.season_id = plus_ancienne.season_id
              AND (a.created_at, a.id) > (plus_ancienne.created_at, plus_ancienne.id)
            SQL);

        // 3. Supprimer les doublons de tags, désormais sans assignation.
        $this->addSql(<<<'SQL'
            DELETE FROM team_tag t
            USING (
                SELECT DISTINCT ON (club_id, name) id, club_id, name
                FROM team_tag
                ORDER BY club_id, name, created_at, id
            ) gardee
            WHERE t.club_id = gardee.club_id AND t.name = gardee.name AND t.id <> gardee.id
            SQL);

        // 4. L'invariant, enfin gardé par la base.
        $this->addSql('CREATE UNIQUE INDEX uniq_team_tag_club_name ON team_tag (club_id, name)');
    }

    public function down(Schema $schema): void
    {
        // ⚠ IRRÉVERSIBLE, et pas seulement parce que les doublons supprimés ne se restaurent
        // pas (revue #356). Le code de P4-64 DÉPEND de cet index : `insertMissingSystemTags`
        // émet `ON CONFLICT (club_id, name)`, que PostgreSQL refuse sans index unique
        // correspondant (SQLSTATE 42P10). Reculer d'un cran pendant que les conteneurs
        // servent ferait donc échouer TOUTE création d'équipe — l'exception remonte de
        // `TeamTagSyncListener::postFlush` et avorte le flush englobant : 500, équipe non
        // créée. Le `down()` d'origine, lui, se contentait de supprimer l'index en silence.
        //
        // Le retour arrière d'une release fautive passe par la restauration du dump pris
        // AVANT la migration (`docs/ops/backup-restore.md` §3), pas par `migrate prev`.
        $this->throwIrreversibleMigrationException(
            'P4-64 : reculer supprimerait uniq_team_tag_club_name, dont ON CONFLICT dépend — '
            . 'toute création d\'équipe échouerait en 42P10. Restaurer le dump pré-migration '
            . '(docs/ops/backup-restore.md §3) plutôt que de jouer ce down().',
        );
    }
}
