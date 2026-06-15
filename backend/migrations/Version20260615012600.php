<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615012600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop old constraint entities tables (TeamConstraint, VenueConstraint, CoachUnavailability, VenueAvailability, VenueClosure)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS team_constraint CASCADE');
        $this->addSql('DROP TABLE IF EXISTS venue_constraint CASCADE');
        $this->addSql('DROP TABLE IF EXISTS coach_unavailability CASCADE');
        $this->addSql('DROP TABLE IF EXISTS venue_availability CASCADE');
        $this->addSql('DROP TABLE IF EXISTS venue_closure CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Cannot recreate tables without entity definitions - migration is one-way
    }
}
