<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SA3-C: identify scheduled job slots and prevent duplicate execution.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_job_run ADD scheduled_for TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_job_scheduled_slot ON admin_job_run (job_key, scheduled_for) WHERE scheduled_for IS NOT NULL AND status <> \'interrupted\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_admin_job_scheduled_slot');
        $this->addSql('ALTER TABLE admin_job_run DROP scheduled_for');
    }
}
