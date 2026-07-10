<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ENG-21: SOFT slot locks were a placebo — the engine never honored the soft-lock penalty,
 * so a SOFT-locked slot was silently movable while the UI showed it "locked". SOFT is now
 * rejected at the write endpoint; convert any pre-existing SOFT row to NONE so the stored
 * state matches reality (it was never actually locked).
 */
final class Version20260710120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ENG-21: normalise placebo SOFT slot locks to NONE.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE schedule_slot_template SET lock_level = \'NONE\' WHERE lock_level = \'SOFT\'');
    }

    public function down(Schema $schema): void
    {
        // Irreversible: the original SOFT values are unrecoverable (and were a no-op anyway).
    }
}
