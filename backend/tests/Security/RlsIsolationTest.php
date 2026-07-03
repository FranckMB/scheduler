<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\TenantConnectionContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SEC-03 non-regression: the DATABASE itself enforces tenant isolation.
 * Raw SQL on the runtime (app_user) connection — no ORM, no Doctrine filter:
 * these tests prove the RLS policies work even if every application layer is
 * bypassed. dama wraps each test in a transaction (rollback cleans the rows
 * AND the session GUC, set_config(..., false) being transactional).
 */
#[Group('phase1')]
#[Group('integration')]
final class RlsIsolationTest extends KernelTestCase
{
    private const CLUB_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const CLUB_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Connection $connection;

    private TenantConnectionContext $guc;

    public function testConnectionUserIsNotSuperuser(): void
    {
        // Guard against a DATABASE_URL regression back to the superuser: with a
        // superuser (or table owner without FORCE), every policy is bypassed and
        // all the assertions below would silently test nothing.
        $superuser = $this->connection->fetchOne(
            'SELECT usesuper FROM pg_user WHERE usename = current_user',
        );
        self::assertFalse((bool) $superuser, 'runtime connection must NOT be a superuser');
    }

    public function testEveryClubIdTableIsUnderForcedRls(): void
    {
        // Coverage guard: the migration hardcodes the table list — a future
        // migration adding a club_id table without RLS would silently open a
        // tenant hole. Enumerate club_id tables dynamically and require
        // ENABLE + FORCE + at least one policy on each.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS table_name,
                   c.relrowsecurity AS rls_enabled,
                   c.relforcerowsecurity AS rls_forced,
                   (SELECT count(*) FROM pg_policies p WHERE p.schemaname = 'public' AND p.tablename = c.relname) AS policies
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped
              )
            SQL);

        self::assertNotEmpty($rows, 'expected club_id tables to exist');
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            self::assertTrue((bool) $row['rls_enabled'], \sprintf('table %s owns club_id but RLS is not ENABLED — add it to the RLS migration', $table));
            self::assertTrue((bool) $row['rls_forced'], \sprintf('table %s owns club_id but RLS is not FORCED', $table));
            self::assertGreaterThan(0, (int) $row['policies'], \sprintf('table %s owns club_id but has no policy', $table));
        }
    }

    public function testGucScopedSelectCannotSeeOtherClub(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        $this->guc->setClubId(self::CLUB_A);
        /** @var list<string> $clubIds */
        $clubIds = $this->connection->fetchFirstColumn('SELECT DISTINCT club_id FROM team_tag');

        self::assertSame([self::CLUB_A], $clubIds, 'club A must only ever see its own rows');
    }

    public function testNoGucSeesNoRows(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        $this->guc->clear();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM team_tag'), 'no GUC → fail-closed, zero rows, no error');
    }

    public function testCrossTenantUpdateAndDeleteAffectZeroRows(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        $this->guc->setClubId(self::CLUB_A);
        $updated = $this->connection->executeStatement(
            'UPDATE team_tag SET name = ? WHERE club_id = ?',
            ['pwned', self::CLUB_B],
        );
        $deleted = $this->connection->executeStatement(
            'DELETE FROM team_tag WHERE club_id = ?',
            [self::CLUB_B],
        );

        self::assertSame(0, $updated, 'cross-tenant UPDATE must touch zero rows');
        self::assertSame(0, $deleted, 'cross-tenant DELETE must touch zero rows');
    }

    public function testInsertWithMismatchedClubIdIsRejected(): void
    {
        $this->seedTwoClubsWithOneTeamEach();

        // GUC = A, row claims club B → WITH CHECK must reject.
        $this->guc->setClubId(self::CLUB_A);
        $this->expectException(DriverException::class);
        $this->insertTeam(self::CLUB_B, 'smuggled');
    }

    public function testClubUserRemainsReadableWithoutGuc(): void
    {
        // Membership bootstrap: the tenant listener / register / /api/me read
        // club_user BEFORE any club is known. SELECT must work without a GUC.
        $this->seedTwoClubsWithOneTeamEach();
        $userId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->connection->executeStatement(
            'INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (?, 1, now(), now(), ?, ?, ?, ?)',
            [$userId, 'rls-bootstrap@test.fr', 'x', 'R', 'B'],
        );
        $this->guc->setClubId(self::CLUB_A);
        $this->connection->executeStatement(
            'INSERT INTO club_user (id, version, created_at, updated_at, club_id, user_id, role, joined_at, is_active) VALUES (?, 1, now(), now(), ?, ?, ?, now(), true)',
            ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', self::CLUB_A, $userId, 'admin'],
        );

        $this->guc->clear();
        $count = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM club_user WHERE user_id = ?',
            [$userId],
        );
        self::assertSame(1, $count, 'club_user must stay readable without a GUC (tenant bootstrap)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->guc = self::getContainer()->get(TenantConnectionContext::class);
    }

    private function seedTwoClubsWithOneTeamEach(): void
    {
        foreach ([self::CLUB_A => 'RLSA', self::CLUB_B => 'RLSB'] as $clubId => $slug) {
            // club has no club_id column → no RLS, insert freely.
            $this->connection->executeStatement(
                'INSERT INTO club (id, version, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, 1, now(), now(), ?, ?, ?, ?, true, 0)',
                [$clubId, 'Club ' . $slug, strtolower($slug) . '-' . substr(md5($clubId), 0, 6), 'Europe/Paris', 'fr'],
            );
            $this->guc->setClubId($clubId);
            $this->insertTeam($clubId, 'Team ' . $slug);
        }
        $this->guc->clear();
    }

    private function insertTeam(string $clubId, string $name): void
    {
        // team_tag is the leanest club-scoped table (no FK chain) — the policy
        // template is identical on every tenant table.
        $this->connection->executeStatement(
            'INSERT INTO team_tag (id, version, created_at, updated_at, club_id, name, is_system) VALUES (gen_random_uuid(), 1, now(), now(), ?, ?, false)',
            [$clubId, $name],
        );
    }
}
