<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait des champs morts `temporaryLock*` de `schedule_slot_template` (contrat 2.9).
 *
 * Trois colonnes que le solveur n'a jamais lues et que plus aucun writer ne renseigne
 * (CRUD read-only) : `temporary_lock`, `temporary_lock_for`, `temporary_min_sessions_override`.
 * Le schéma moteur est `extra="forbid"`, donc leur retrait est atomique côté payload —
 * cette migration aligne la base sur l'entité.
 *
 * Générée à la main : `doctrine:migrations:diff` est inopérant dans cet environnement
 * (doctrine/dbal 4.4.4, un abonné de génération de schéma exige `Schema::edit()`, DBAL ^4.5).
 * DDL calquée sur le `CREATE TABLE` d'origine (`Version20260608232552`).
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contrat 2.9: drop schedule_slot_template.temporary_lock / temporary_lock_for / temporary_min_sessions_override (dead fields).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_slot_template DROP COLUMN temporary_lock');
        $this->addSql('ALTER TABLE schedule_slot_template DROP COLUMN temporary_lock_for');
        $this->addSql('ALTER TABLE schedule_slot_template DROP COLUMN temporary_min_sessions_override');
    }

    public function down(Schema $schema): void
    {
        // Backfill des lignes existantes via DEFAULT, puis on le retire : la colonne d'origine
        // était NOT NULL sans défaut SQL (défaut PHP `false`). Patron `causes` (Version20260815130000).
        $this->addSql('ALTER TABLE schedule_slot_template ADD temporary_lock BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE schedule_slot_template ALTER COLUMN temporary_lock DROP DEFAULT');
        $this->addSql('ALTER TABLE schedule_slot_template ADD temporary_lock_for UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_slot_template ADD temporary_min_sessions_override SMALLINT DEFAULT NULL');
    }
}
