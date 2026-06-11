<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable severity to team constraints';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('team_constraint')->hasColumn('severity')) {
            $this->addSql('ALTER TABLE team_constraint ADD severity VARCHAR(30) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('team_constraint')->hasColumn('severity')) {
            $this->addSql('ALTER TABLE team_constraint DROP severity');
        }
    }
}
