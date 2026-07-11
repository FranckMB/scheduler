<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RGPD PR-5 (consentement) : preuve d'acceptation des CGU/politique de
 * confidentialité au register — horodatage + version des textes.
 */
final class Version20260711220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RGPD consentement : app_user.terms_accepted_at + terms_version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD terms_version VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP terms_accepted_at');
        $this->addSql('ALTER TABLE app_user DROP terms_version');
    }
}
