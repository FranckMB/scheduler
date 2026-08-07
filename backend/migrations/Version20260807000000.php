<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-16 / P2-4 — l'« aujourd'hui » simulé d'un club de démonstration.
 *
 * NULL (tous les vrais clubs) = horloge réelle, zéro changement de comportement.
 * Non-null = le serveur (DemoAwareClock) et le front (/api/me → clock.ts) vivent
 * à cette date pour ce club — rejouer une situation datée en démo.
 */
final class Version20260807000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-16/P2-4: club.demo_today — simulated today for demo clubs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD demo_today DATE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN club.demo_today IS \'(DC2Type:date_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP demo_today');
    }
}
