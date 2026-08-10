<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P1-1 (PR B) — `club_user.deactivated_at` : l'horodatage qui DISTINGUE une
 * adhésion désactivée (sortie) d'une adhésion en attente (jamais entrée). Les
 * deux sont `is_active = false` ; seule la désactivée porte cette colonne.
 *
 * Nullable, sans contrainte ni valeur par défaut : toutes les lignes existantes
 * restent en attente/actives (`NULL`), et aucune valeur de rôle historique
 * n'est touchée. Table `club_user` déjà keyée `club_id` → policies RLS inchangées.
 */
final class Version20260810140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P1-1 PR B : club_user.deactivated_at (timestamptz nullable) — sépare désactivé de en-attente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_user ADD deactivated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_user DROP deactivated_at');
    }
}
