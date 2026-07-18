<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-5 E1 (fondateur 2026-07-18) — plans de période À LA SEMAINE : une entrée
 * calendrier ENFANT couvre une semaine pleine d'une période mère et porte son
 * propre plan par le rail existant (1 entrée = 1 plan). `parent_entry_id` null =
 * entrée racine. Pas de FK physique : la cascade est applicative
 * (deleteEntryAndCascade), comme le reste des liens calendrier.
 */
final class Version20260718220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-5 E1: calendar_entry.parent_entry_id (semaines enfants d\'une période).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry ADD parent_entry_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_calendar_entry_parent ON calendar_entry (parent_entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_calendar_entry_parent');
        $this->addSql('ALTER TABLE calendar_entry DROP parent_entry_id');
    }
}
