<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-21 lot A (correctif revue CI) — marqueur SERVEUR de l'import d'équipes.
 *
 * `club.ffbb_teams_imported_at` : non-null = les équipes du club ont été créées
 * par l'import FFBB à l'onboarding. La modale « équipes importées » et
 * l'atterrissage forcé sur Équipes se gataient sur la seule ABSENCE d'un flag
 * localStorage : tout club avec des équipes et un navigateur vierge (saisie
 * manuelle, ou chaque spec e2e) voyait la modale mentir — la suite e2e CI est
 * restée suspendue 30 min dessus. Pas de backfill : les clubs existants n'ont
 * pas été importés.
 */
final class Version20260804210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-21 lot A: club.ffbb_teams_imported_at — server truth gating the import notice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD ffbb_teams_imported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP ffbb_teams_imported_at');
    }
}
