<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-5 — l'abonnement se paie PAR SAISON (décision fondateur 2026-08-04).
 *
 * `club.paid_season_year` = l'année-pivot de la dernière saison réglée (2026 =
 * saison 2026-2027). La bascule vers N+1 exigera paid_season_year >= N+1 : le
 * gate est la BASCULE — générer sa saison suivante dès mai puis résilier était
 * la fuite de revenu constatée. Backfill : les clubs existants sont réputés en
 * règle pour leur saison EN COURS (pivot 15 juillet), pas au-delà — leur
 * prochaine bascule exigera le geste « saison payée ».
 */
final class Version20260804230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P1-5: club.paid_season_year — per-season payment marker gating the transition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD paid_season_year INT DEFAULT NULL');
        // L'année-pivot de la saison courante : après le 15 juillet de l'année Y
        // c'est Y, avant c'est Y-1 (même règle que SeasonResolver::seasonYear).
        $this->addSql(<<<'SQL'
            UPDATE club SET paid_season_year = CASE
                WHEN (CURRENT_DATE >= make_date(EXTRACT(YEAR FROM CURRENT_DATE)::int, 7, 15))
                    THEN EXTRACT(YEAR FROM CURRENT_DATE)::int
                ELSE EXTRACT(YEAR FROM CURRENT_DATE)::int - 1
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP paid_season_year');
    }
}
