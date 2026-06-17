<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617162122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DEFAULT 1 to venue_availability.version column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_availability ALTER COLUMN version SET DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_availability ALTER COLUMN version DROP DEFAULT');
    }
}
