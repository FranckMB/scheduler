<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-4 PR D — `fixture.placement_source` (MANUAL|SOLVER, nullable) : le marqueur
 * qui rend le RE-SOLVE possible. MANUAL (et tout SUBMITTED/VALIDATED) = ancre
 * intouchable ; SOLVER = re-arrangeable au prochain solve. Null = jamais placé /
 * lignes d'avant cette migration — traitées MANUAL quand placées (on ne déplace
 * jamais ce qu'on ne peut pas attribuer).
 *
 * Rétro-compat deploy : ajout nullable sans défaut, l'ancienne release l'ignore.
 */
final class Version20260803190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match P1-4 PR D: fixture.placement_source (MANUAL|SOLVER re-solve marker).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD placement_source VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture DROP placement_source');
    }
}
