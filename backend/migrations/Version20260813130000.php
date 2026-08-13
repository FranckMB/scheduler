<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin access policies under FORCE RLS (managed-Postgres provisioning).
 *
 * Runs on the ADMIN connection (clubscheduler). In dev/test the clubscheduler
 * role is a SUPERUSER and bypasses RLS entirely, so these policies are inert
 * there. On a MANAGED provider the owner role is created WITHOUT superuser:
 * FORCE ROW LEVEL SECURITY then applies to the owner too, and with no policy
 * granting it access every FORCE table default-DENIES the admin connection —
 * the superadmin supervision door slams shut. This migration reopens it with
 * one permissive admin_all policy (FOR ALL, USING/ WITH CHECK true) TO
 * clubscheduler on every FORCE table that exists at migration time.
 *
 * The table list is enumerated PHP-side at execution (pg_class), not hardcoded:
 * every table already under FORCE gets its door. FUTURE FORCE tables are NOT
 * covered here — RlsIsolationTest's presence check fails loudly if a later
 * migration forgets the admin_all policy, forcing the fix into that migration.
 *
 * Hybrid tables (club_user, coach_wish_token) and audit_log get the SAME
 * admin_all FOR ALL, no special case — the enumeration covers them. Consequence
 * assumed: the admin role keeps UPDATE/DELETE on audit_log (current behaviour;
 * append-only remains enforced against app_user by the absence of write policies
 * on that connection).
 */
final class Version20260813130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin_all policies (FOR ALL, TO clubscheduler) on every FORCE-RLS table: keeps the admin supervision door open when the owner role is not a superuser (managed Postgres).';
    }

    public function up(Schema $schema): void
    {
        // The clubscheduler role is provisioned per-environment (docker initdb
        // 02-users.sh in dev/test/CI, secure manual provisioning on a managed
        // provider) — never in a git-committed migration. On a managed provider
        // the owner MUST be created under this exact name. Fail loudly if absent.
        $hasRole = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'clubscheduler\'');
        $this->abortIf(
            !$hasRole,
            'Role clubscheduler does not exist. Provision the database owner first, under this exact name (dev/test: docker/postgres/init/02-users.sh; managed provider: the owner role must be named clubscheduler so these admin policies apply to it).',
        );

        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . 'WHERE n.nspname = \'public\' AND c.relforcerowsecurity AND c.relkind IN (\'r\', \'p\') ORDER BY c.relname',
        );

        foreach ($tables as $table) {
            // Always double-quote the relname: some tenant tables are reserved
            // words ("constraint").
            $this->addSql(\sprintf(
                'CREATE POLICY admin_all ON public."%s" FOR ALL TO clubscheduler USING (true) WITH CHECK (true)',
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            'SELECT tablename FROM pg_policies WHERE schemaname = \'public\' AND policyname = \'admin_all\' ORDER BY tablename',
        );

        foreach ($tables as $table) {
            $this->addSql(\sprintf('DROP POLICY admin_all ON public."%s"', $table));
        }
    }
}
