<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Site web officiel du comité et de la ligue (`urlSiteWeb` Meilisearch — cadrage
 * api-ffbb-completion-club §2). Tables de référence partagées, hors tenant/RLS.
 */
final class Version20260804150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ffbb_committee/ffbb_league: website column (urlSiteWeb).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ffbb_committee ADD website VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ffbb_league ADD website VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ffbb_committee DROP website');
        $this->addSql('ALTER TABLE ffbb_league DROP website');
    }
}
