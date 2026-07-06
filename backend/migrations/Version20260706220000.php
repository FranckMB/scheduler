<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data repair: the register seed historically wrote season.end_date one year
 * too early (endDate = Y-07-15 while startDate = Y-08-01 → negative window).
 * The seed is fixed (AuthController::seedNewClub, transition PR-1); this
 * migration heals the rows already written so /api/me seasons[] never exposes
 * an incoherent window. Season resolution is unaffected either way (it keys
 * on startDate only).
 */
final class Version20260706220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair legacy season rows where end_date < start_date (register seed bug).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE season SET end_date = end_date + INTERVAL \'1 year\' WHERE end_date < start_date');
    }

    public function down(Schema $schema): void
    {
        // Irreversible data repair: the pre-fix rows were plain wrong; nothing
        // distinguishes repaired rows from correctly-seeded ones afterwards.
        $this->skipIf(true, 'Data repair migration — not reversible.');
    }
}
