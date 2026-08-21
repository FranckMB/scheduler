<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait de l'export PNG (décision fondateur 2026-08-21) : la colonne `schedule.png_export_url`
 * n'a plus d'écrivain ni de lecteur.
 *
 * Le PNG était une CAPTURE d'une des deux sections déjà rendues par le PDF — il n'apportait pas
 * de vue supplémentaire, seulement un format qui ne se feuillette pas. Le document, lui, porte
 * les deux vues (grille jours × gymnases, puis matrice équipes × jours), et depuis ce même jour
 * il les porte TOUJOURS, quel que soit le nombre de gymnases.
 *
 * ⚠ **`down()` restaure la colonne, pas les fichiers.** Un retour arrière rend le schéma, jamais
 * les URLs : les lignes reviendront à NULL. C'est sans conséquence — plus aucun code ne lit ce
 * champ, et les fichiers `.png` du disque sont ramassés par `app:exports:purge`.
 *
 * Écrite à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste < 4.5.
 */
final class Version20260821120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retrait export PNG: suppression de schedule.png_export_url.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP COLUMN IF EXISTS png_export_url');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD png_export_url VARCHAR(2048) DEFAULT NULL');
    }
}
