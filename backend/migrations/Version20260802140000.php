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
 * ⚠ Rétro-compatibilité (convention `docs/ops/deploy.md`) : le deploy migre AVANT de basculer
 * les conteneurs, donc l'ancien code tourne quelques secondes sur le nouveau schéma. Ajouter
 * un index unique ne casse aucune LECTURE. La seule écriture qu'il peut refuser est
 * précisément l'insertion en double que ce lot supprime — et le code qui l'émettait est celui
 * qu'on remplace dans la même PR.
 */
final class Version20260802140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-64: dédoublonne team_tag puis pose un index unique sur (club_id, name).';
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
        // Les doublons supprimés ne se restaurent pas — et ne doivent pas l'être.
        $this->addSql('DROP INDEX uniq_team_tag_club_name');
    }
}
