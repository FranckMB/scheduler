<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-51 — `coach.max_days_override_confirmed` disparaît : lu par personne, jamais.
 *
 * Le drapeau traversait TOUT le pipeline — entité, DTO, processor, ressource API,
 * payload du solveur, schéma d'entrée du moteur, recopie à la transition de saison —
 * et n'était consommé nulle part : le moteur validait le champ puis ne le lisait
 * jamais (`input_schema.py` le portait, aucune contrainte ni objectif ne s'en
 * servait). Une donnée qui voyage partout et ne décide rien est une promesse de
 * comportement que rien ne tient.
 *
 * Décision fondateur (2026-08-09) : suppression, plutôt que d'inventer un sens
 * après coup. Le plafond lui-même (`max_days_override`) reste — il est appliqué
 * depuis P4-51 (malus soft `overload_day`) et l'écran l'expose désormais.
 *
 * Aucune perte de donnée décisionnelle : le drapeau valait `false` par défaut et
 * aucun écran n'a jamais permis de le passer à `true`.
 */
final class Version20260809160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-51 : drop coach.max_days_override_confirmed — un drapeau que rien ne lisait';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach DROP COLUMN max_days_override_confirmed');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach ADD max_days_override_confirmed BOOLEAN DEFAULT false NOT NULL');
    }
}
