<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-67 — l'unicité qui rend la résolution d'import déterministe.
 *
 * `Competition` acceptait deux lignes identiques pour la même équipe (POST
 * manuel), et deux lignes appariées à la MÊME compétition FFBB : le résolveur
 * d'import (`FbiFixtureImporter::buildCompetitionResolver`) prenait alors
 * `candidates[0]` — arbitraire. Patron de Version20260802140000 (team_tag) :
 * DÉDUP d'abord (les fixtures des doublons sont repointés sur la ligne
 * gardée — la plus ancienne — avant suppression), contraintes ensuite.
 * Irréversible (le down ne recrée pas des doublons).
 */
final class Version20260804170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-67: competition uniqueness — (club, season, team, name)';
    }

    public function up(Schema $schema): void
    {
        // 1. Dédup (club, season, team, name) : repointer les fixtures sur la
        //    ligne gardée (la plus ancienne), puis supprimer les doublons.
        $this->addSql(<<<'SQL'
            WITH keepers AS (
                SELECT id, first_value(id) OVER (PARTITION BY club_id, season_id, team_id, name ORDER BY created_at, id) AS keeper_id
                FROM competition
            )
            UPDATE fixture f SET competition_id = k.keeper_id
            FROM keepers k
            WHERE f.competition_id = k.id AND k.id <> k.keeper_id
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM competition c USING (
                SELECT id, first_value(id) OVER (PARTITION BY club_id, season_id, team_id, name ORDER BY created_at, id) AS keeper_id
                FROM competition
            ) k
            WHERE c.id = k.id AND k.id <> k.keeper_id
            SQL);

        // ⚠ PAS d'unicité sur ffbb_competition_id : deux équipes d'un club
        //    peuvent jouer la MÊME compétition FFBB (deux U11 dans la même
        //    division — le cas multiLabel de l'importeur). La réf n'est une clé
        //    naturelle que PAR équipe, déjà couverte par l'unicité ci-dessous.
        $this->addSql('CREATE UNIQUE INDEX uniq_competition_team_name ON competition (club_id, season_id, team_id, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_competition_team_name');
    }
}
