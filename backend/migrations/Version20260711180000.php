<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RGPD PR-3 (rétention) :
 * - app_user.last_login_at — dernier login réussi (inactivité mesurée sur
 *   COALESCE(last_login_at, created_at)).
 * - app_user.inactivity_warned_at — préavis d'inactivité envoyé (23 mois),
 *   remis à null au login ; l'anonymisation (24 mois) exige un préavis ≥ 14 j.
 */
final class Version20260711180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RGPD rétention : app_user.last_login_at + inactivity_warned_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD inactivity_warned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
        $this->addSql('ALTER TABLE app_user DROP inactivity_warned_at');
    }
}
