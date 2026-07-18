<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0002 lot D-b — drop `calendar_entry.overlay_schedule_id` (clôt l'ADR).
 *
 * Vestige du palier B : un pointeur inverse « version overlay ACTIVE de la période »,
 * auto-posé à la création. Il fait doublon avec le pattern Plan — « période → version
 * active » se dérive désormais de son plan (`schedule_plan.chosen_schedule_id`), binaire :
 * plan validé → on montre la version choisie, plan non validé → aucune version active.
 *
 * V0, rien en prod ; la colonne n'a plus aucun lecteur/écrivain côté code (readers dérivent
 * du plan). Le drop est donc sûr et sans backfill.
 */
final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-0002 lot D-b: drop calendar_entry.overlay_schedule_id (version active dérivée du plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP COLUMN overlay_schedule_id');
    }

    public function down(Schema $schema): void
    {
        // Réversibilité du schéma uniquement — la valeur (pointeur actif) ne se restaure pas
        // (dérivable du plan : chosen_schedule_id de schedule_plan.calendar_entry_id).
        $this->addSql('ALTER TABLE calendar_entry ADD overlay_schedule_id UUID DEFAULT NULL');
    }
}
