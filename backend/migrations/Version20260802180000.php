<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-4 PR A — import FBI sur le format réel : deux colonnes de rattachement FBI.
 *
 * `fixture.fbi_venue_label` : le libellé « Salle » de l'export, stocké domicile ET
 * extérieur (cadrage P1-4 F3 — la salle extérieure est la matière première du trajet,
 * gratuite dans le fichier). Jamais résolu vers `venue` en PR A.
 *
 * `competition.fbi_team_label` : le libellé côté club tel que l'export l'écrit
 * (« B CHARPENNES CROIX LUIZET - 2 ») — le désambiguïsateur quand DEUX équipes du club
 * jouent dans la MÊME division (décision fondateur 2026-08-02 : « si y a une deuxième
 * équipe elle s'appellera BCCL - 2, ça permettra d'apparier la 2ᵉ team »). Null quand la
 * division seule identifie l'équipe (cas nominal, 14/14 divisions de l'échantillon).
 *
 * Rétro-compatibilité (convention docs/ops/deploy.md) : ajouts nullables sans défaut —
 * l'ancienne release ne les lit ni ne les écrit, aucune fenêtre d'échec. RLS : les deux
 * tables sont déjà sous FORCE ROW LEVEL SECURITY avec des policies portées par la TABLE
 * (Version20260706240000) — une colonne de plus ne les touche pas.
 */
final class Version20260802180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match P1-4 PR A: fixture.fbi_venue_label + competition.fbi_team_label (real FBI import).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD fbi_venue_label VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD fbi_team_label VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture DROP fbi_venue_label');
        $this->addSql('ALTER TABLE competition DROP fbi_team_label');
    }
}
