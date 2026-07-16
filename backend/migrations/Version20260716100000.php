<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SA0: separate super-admin identities and restricted admin access audit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE super_admin (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, totp_secret TEXT NOT NULL, enabled BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_super_admin_email ON super_admin (LOWER(email))');
        $this->addSql('CREATE TABLE admin_audit_log (id UUID NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, super_admin_id UUID DEFAULT NULL, action VARCHAR(80) NOT NULL, route VARCHAR(180) DEFAULT NULL, status_code INT DEFAULT NULL, details JSONB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_audit_occurred_at ON admin_audit_log (occurred_at)');
        $this->addSql('CREATE INDEX idx_admin_audit_actor ON admin_audit_log (super_admin_id)');
        $this->addSql('REVOKE ALL ON super_admin FROM app_user');
        $this->addSql('REVOKE ALL ON admin_audit_log FROM app_user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_audit_log');
        $this->addSql('DROP TABLE super_admin');
    }
}
