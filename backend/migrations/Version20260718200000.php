<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alerting superadmin — état anti-spam des alertes (`admin_alert_state`) : une ligne
 * par check, transitions ok↔firing. Table d'exploitation sur la connexion admin
 * (pattern admin_job_run) : le rôle runtime app_user n'y a AUCUN privilège.
 */
final class Version20260718200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alerting: admin_alert_state (anti-spam des alertes superadmin, connexion admin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_alert_state (check_key VARCHAR(80) NOT NULL, status VARCHAR(10) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_alerted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (check_key), CONSTRAINT chk_admin_alert_state_status CHECK (status IN (\'ok\', \'firing\')))');
        $this->addSql('REVOKE ALL ON admin_alert_state FROM app_user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_alert_state');
    }
}
