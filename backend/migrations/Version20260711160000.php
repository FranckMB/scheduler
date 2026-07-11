<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RGPD PR-1 (droit à l'effacement) :
 * - app_user.anonymized_at — non-null = compte anonymisé (identité écrasée).
 * - club.erasure_scheduled_at — purge du workspace programmée (dernier admin
 *   effacé + délai de grâce 30 j) ; annulable tant que la purge n'a pas couru.
 * - club.unsubscribed_at — workspace purgé, seule l'identité publique FFBB
 *   subsiste (référentiel adverse / win-back).
 */
final class Version20260711160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RGPD: app_user.anonymized_at + club.erasure_scheduled_at / unsubscribed_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD anonymized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD erasure_scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD unsubscribed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP anonymized_at');
        $this->addSql('ALTER TABLE club DROP erasure_scheduled_at');
        $this->addSql('ALTER TABLE club DROP unsubscribed_at');
    }
}
