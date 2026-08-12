<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-79 — `team.allow_multiple_sessions_per_day` disparaît : personne ne l'écrit.
 *
 * Le drapeau exemptait une équipe de la règle implicite « une séance par jour »
 * côté moteur (`add_one_session_per_day_constraints`), et il traversait tout le
 * pipeline — entité, ressource API (lecture seule), payload du solveur, schéma
 * d'entrée du moteur, recopie à la bascule de saison. Mais il était **absent de
 * `TeamInput`** : aucune route ne l'écrivait, aucun écran ne l'exposait. Il valait
 * donc `false` partout et la branche d'exemption du solveur était morte.
 *
 * Décision fondateur (règle « use-case-check-first ») : retrait de bout en bout,
 * jumeau du drapeau supprimé en P4-51 — plutôt que d'inventer un sens après coup.
 *
 * Aucune perte de donnée décisionnelle : le défaut valait `false` et aucun écran
 * n'a jamais permis de le passer à `true`.
 */
final class Version20260812150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-79 : drop team.allow_multiple_sessions_per_day — un levier de solveur que rien n\'écrivait';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team DROP COLUMN allow_multiple_sessions_per_day');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE team ADD allow_multiple_sessions_per_day BOOLEAN DEFAULT false NOT NULL');
    }
}
