<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot register-UX (retour fondateur 2026-07-18) : `club.sport_id` — le club sait
 * son sport, de première main (jusqu'ici seulement dérivable via SportCategory).
 * Référence l'entité `Sport` existante. Nullable + backfill depuis les
 * SportCategory du club (toutes basketball aujourd'hui) ; un club sans catégorie
 * reste null. Aucune FK physique posée (le reste du schéma club_id/RLS n'en met pas
 * sur les tables de référence hors-tenant — `sport` est globale, hors RLS).
 */
final class Version20260718210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'register-UX: club.sport_id (nullable, backfill depuis sport_category).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD sport_id UUID DEFAULT NULL');
        // Backfill : le sport d'un club = celui de ses catégories (une seule valeur
        // par club aujourd'hui). Sous-requête corrélée + LIMIT 1 (déterministe via
        // ORDER BY ; Postgres n'a pas MIN(uuid)).
        $this->addSql(<<<'SQL'
            UPDATE club c SET sport_id = (
                SELECT sc.sport_id FROM sport_category sc
                WHERE sc.club_id = c.id ORDER BY sc.sport_id LIMIT 1
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP sport_id');
    }
}
