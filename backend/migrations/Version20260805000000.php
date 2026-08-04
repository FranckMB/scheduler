<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P3-4 — anti-squatting du code club : la table des demandes de CRÉATION.
 *
 * Le premier inscrivant d'un ARA inconnu ne matérialise plus le club à la
 * vérification d'email : sa demande (`club_creation_request`) attend
 * l'approbation du club lui-même (lien au mail institutionnel FFBB) ou du
 * superadmin. Pas de `club_id` (le club n'existe pas encore) → hors RLS,
 * comme les tables de référence. Token secret en clair (patron
 * coach_wish_token : page publique sans compte, 404 byte-identique).
 */
final class Version20260805000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P3-4: club_creation_request — first-manager approval before club creation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE club_creation_request (
                id UUID NOT NULL,
                token VARCHAR(64) NOT NULL,
                user_id UUID NOT NULL,
                ara VARCHAR(20) NOT NULL,
                club_name VARCHAR(255) NOT NULL,
                club_email VARCHAR(255) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                reminded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_club_creation_request_token ON club_creation_request (token)');
        $this->addSql('CREATE INDEX idx_club_creation_request_ara ON club_creation_request (ara)');
        $this->addSql('CREATE INDEX idx_club_creation_request_status ON club_creation_request (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE club_creation_request');
    }
}
