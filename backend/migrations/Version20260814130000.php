<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-95 — un diagnostic `conflict` devient cliquable jusqu'au créneau fautif. ADD COLUMN nullable UNIQUEMENT.
 *
 * `schedule_diagnostic` gagne `day_of_week` (SMALLINT) et `start_time` (VARCHAR(5), « HH:MM ») :
 * le jour + l'heure de la séance en conflit, portés par l'engine (schéma 2.6) et perdus jusqu'ici
 * à l'import. Nullable car seul le type `conflict` les renseigne (les 10 autres types restent NULL).
 * Donnée d'affichage additive — le solveur ne les consomme pas, le contrat backend⇄engine ne bouge pas.
 */
final class Version20260814130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-95 conflict slot: schedule_diagnostic +day_of_week (SMALLINT nullable) +start_time (VARCHAR(5) nullable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_diagnostic ADD day_of_week SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_diagnostic ADD start_time VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_diagnostic DROP COLUMN day_of_week');
        $this->addSql('ALTER TABLE schedule_diagnostic DROP COLUMN start_time');
    }
}
