<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Club appearance: a distinct accent colour for the DARK theme. The existing
 * accent_color is now the LIGHT accent; accent_color_dark is optional (null =
 * derive the dark accent from the light one, previous behaviour). No RLS change
 * (the club table carries no club_id and is scoped in its provider).
 */
final class Version20260708090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Club: accent_color_dark (distinct accent for the dark theme, nullable).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD accent_color_dark VARCHAR(9) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP accent_color_dark');
    }
}
