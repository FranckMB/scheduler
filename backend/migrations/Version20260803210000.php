<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-4 PR F — FFBB pairing refs on `competition` (appariement §3, décisions
 * fondateur 2026-08-03) : refs de l'engagement apparié (compétition + poule),
 * nom canonique (clé de pré-remplissage inter-phases), attendu de complétude
 * figé à l'appariement (2×(N−1)), et la liste des clubs de la poule copiée
 * (donnée tenant née d'une consultation du club pour lui-même — le garde-fou
 * d'import reste hors-réseau). Écrites UNIQUEMENT par le endpoint de confirm,
 * jamais par le CRUD.
 *
 * Rétro-compat deploy : ajouts nullables sans défaut, l'ancienne release ignore.
 */
final class Version20260803210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match P1-4 PR F: FFBB pairing refs + completeness expectation on competition.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition ADD ffbb_competition_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD ffbb_poule_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD ffbb_poule_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD ffbb_competition_name VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD expected_matchdays INT DEFAULT NULL');
        $this->addSql('ALTER TABLE competition ADD ffbb_poule_opponents JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition DROP ffbb_competition_id');
        $this->addSql('ALTER TABLE competition DROP ffbb_poule_id');
        $this->addSql('ALTER TABLE competition DROP ffbb_poule_name');
        $this->addSql('ALTER TABLE competition DROP ffbb_competition_name');
        $this->addSql('ALTER TABLE competition DROP expected_matchdays');
        $this->addSql('ALTER TABLE competition DROP ffbb_poule_opponents');
    }
}
