<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create venue_constraint table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('venue_constraint');
        $table->addColumn('id', 'guid');
        $table->addColumn('version', 'integer', ['default' => 1]);
        $table->addColumn('created_at', 'datetimetz_immutable');
        $table->addColumn('updated_at', 'datetimetz_immutable');
        $table->addColumn('club_id', 'guid');
        $table->addColumn('season_id', 'guid');
        $table->addColumn('venue_id', 'guid');
        $table->addColumn('constraint_type', 'string', ['length' => 30]);
        $table->addColumn('constraint_value', 'string', ['length' => 20]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['club_id', 'season_id'], 'idx_venue_constraint_club_season');
        $table->addIndex(['venue_id'], 'idx_venue_constraint_venue');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('venue_constraint');
    }
}
