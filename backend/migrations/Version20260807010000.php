<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-4 — le flag « club de démonstration » : hors métriques console, horloge
 * simulable, exempté du futur bridage. FALSE pour tout club existant.
 */
final class Version20260807010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-4: club.is_demo — demo club flag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD is_demo BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP is_demo');
    }
}
