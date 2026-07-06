<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Season transition PR-2: N→N+1 lineage pointer on constraints, mirroring the
 * existing parent_team_id / parent_venue_id / parent_coach_id columns.
 * Nullable guid + index; populated by SeasonTransitionService at copy time.
 */
final class Version20260706230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'constraint.parent_constraint_id lineage column (season transition copy).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "constraint" ADD parent_constraint_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_constraint_parent ON "constraint" (parent_constraint_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_constraint_parent');
        $this->addSql('ALTER TABLE "constraint" DROP parent_constraint_id');
    }
}
