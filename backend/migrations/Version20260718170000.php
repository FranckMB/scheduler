<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SA4 — catalogue d'actions support : l'historique `admin_job_run` gagne une colonne
 * `arguments` (JSON nullable) pour tracer « quelle action, sur QUEL club ». Les
 * arguments viennent exclusivement du catalogue fermé + le `--club` injecté par le
 * controller — jamais d'une saisie libre. null pour les jobs sans paramètre.
 */
final class Version20260718170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SA4: admin_job_run.arguments (JSON) — trace des actions club paramétrées.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_job_run ADD arguments JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_job_run DROP COLUMN arguments');
    }
}
