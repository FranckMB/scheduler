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

    public function testEveryPolicyOnClubIdTablesIsTenantScoped(): void
    {
        // SEC-12 — PORTÉE des policies. testEveryClubIdTableIsUnderForcedRls ne
        // vérifie que ENABLE+FORCE+count>0 : une policy USING (true) copiée-collée
        // sur une future table tenant passerait ce gate sans rougir. Ici on juge
        // CHAQUE policy permissive contre le prédicat canonique.
        //
        // Ce que ce test NE couvre PAS (volontaire, fail-noisy) :
        //  - un prédicat sémantiquement équivalent mais écrit autrement échouera :
        //    l'égalité est STRICTE contre le canon runtime, pas une preuve sémantique ;
        //  - la justesse sémantique du canon lui-même est portée par les tests
        //    comportementaux voisins (testGucScoped* / testCrossTenant* / testInsert*),
        //    jamais par celui-ci.

        // D1 : le canon est LU À L'EXÉCUTION depuis team_tag.tenant_isolation, jamais
        // une chaîne en dur — PostgreSQL reformate les prédicats (casts ::text,
        // parenthèses ajoutées), donc pg_policies.qual ne ressemble pas à ce
        // qu'écrivent les migrations. team_tag est l'étalon : son isolation est déjà
        // prouvée par les tests comportementaux de ce fichier.
        $canon = $this->connection->fetchOne(
            'SELECT qual FROM pg_policies WHERE schemaname = \'public\' AND tablename = \'team_tag\' AND policyname = \'tenant_isolation\'',
        );
        self::assertIsString($canon, 'canon policy team_tag.tenant_isolation must exist');
        self::assertNotSame('', $canon, 'canon predicate must not be empty — sinon le test se compare à du néant');
        self::assertNotSame('true', $canon, 'canon predicate must not be USING (true) — sinon le test ne prouve plus rien');

        // D2 : allowlist en paires (table, commande) dont le SELECT ouvert (USING true)
        // est DÉLIBÉRÉ et JUSTIFIÉ. Ces tables restent soumises au canon pour
        // INSERT/UPDATE/DELETE (vérifié automatiquement par le balayage ci-dessous).
        $openSelectAllowlist = [
            // Bootstrap du tenant depuis les memberships AVANT de connaître le club
            // (listener, register, /api/me) → SELECT ouvert requis.
            'club_user.SELECT' => 'true',
            // Route publique sans JWT : le token porte le club, il faut le lire AVANT
            // de poser le GUC app.club_id → SELECT ouvert requis.
            'coach_wish_token.SELECT' => 'true',
        ];

        // D4 : audit_log INSERT n'est PAS ouvert mais s'écarte du canon par FORME —
        // les actions hors club (club_id NULL) sont journalisables. Composé À PARTIR
        // du canon runtime → robuste au déparseur au même titre que D1. L'absence de
        // policy UPDATE/DELETE sur audit_log ne demande rien : sous FORCE, pas de
        // policy = deny (append-only tenu par la base).
        $insertPredicateOverrides = [
            'audit_log.INSERT' => \sprintf('((club_id IS NULL) OR %s)', $canon),
        ];

        // D6 : réutilise l'énumération dynamique des tables portant club_id (cf.
        // testEveryClubIdTableIsUnderForcedRls), jointe à pg_policies.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS table_name, p.policyname, p.cmd, p.qual, p.with_check, p.permissive
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_policies p ON p.schemaname = 'public' AND p.tablename = c.relname
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND EXISTS (
                  SELECT 1 FROM pg_attribute a
                  WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped
              )
            ORDER BY c.relname, p.policyname
            SQL);
        self::assertNotEmpty($rows, 'expected policies on club_id tables');

        $consumedAllowlist = [];
        $consumedOverrides = [];

        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $policy = (string) $row['policyname'];
            $cmd = (string) $row['cmd'];
            $qual = null === $row['qual'] ? '' : (string) $row['qual'];
            $withCheck = null === $row['with_check'] ? '' : (string) $row['with_check'];

            // Une policy RESTRICTIVE ne fait que RESTREINDRE (sémantique ET) : elle ne
            // peut pas ouvrir une table. Seules les policies PERMISSIVE participent au
            // OU qui peut accorder l'accès — ce sont elles qu'on juge. (Aucune
            // restrictive aujourd'hui ; garde défensive, cf. le OU permissif de D0.)
            if ('PERMISSIVE' !== (string) $row['permissive']) {
                continue;
            }

            $key = $table . '.' . $cmd;
            $where = \sprintf('%s.%s (%s)', $table, $policy, $cmd);

            // Tenant-scopée contre le canon → correct, rien de plus à vérifier. On
            // JUGE CHAQUE policy séparément : PostgreSQL fait un OU entre policies
            // permissives, donc une tenant_isolation saine à côté d'un USING (true)
            // laisse la table ouverte — d'où le fail plus bas, sans « il en existe
            // une bonne ».
            if ($this->policyMatchesCanon($cmd, $qual, $withCheck, $canon)) {
                continue;
            }

            // À partir d'ici la policy S'ÉCARTE du canon → acceptable UNIQUEMENT si
            // explicitement justifiée. D4 : override de FORME sur audit_log INSERT.
            if (isset($insertPredicateOverrides[$key])) {
                self::assertSame(
                    $insertPredicateOverrides[$key],
                    $withCheck,
                    \sprintf(
                        '%s : WITH CHECK diverge de sa dérogation justifiée — attendu %s, trouvé %s. Vérifier la forme exacte en base.',
                        $where,
                        $insertPredicateOverrides[$key],
                        '' === $withCheck ? '<none>' : $withCheck,
                    ),
                );
                $consumedOverrides[$key] = true;

                continue;
            }

            // D2 : SELECT ouvert allowlisté (bootstrap club_user / coach_wish_token).
            if (isset($openSelectAllowlist[$key])) {
                self::assertSame(
                    $openSelectAllowlist[$key],
                    $qual,
                    \sprintf(
                        '%s : allowlistée comme SELECT ouvert mais le prédicat est %s, pas le %s attendu. Scoper au GUC, ou corriger la dérogation.',
                        $where,
                        '' === $qual ? '<none>' : $qual,
                        $openSelectAllowlist[$key],
                    ),
                );
                $consumedAllowlist[$key] = true;

                continue;
            }

            // Écart non justifié → la fuite que D0 garde.
            self::fail(\sprintf(
                '%s N\'EST PAS tenant-scopée : USING=%s WITH CHECK=%s, canon attendu %s. Une policy permissive hors-canon OUVRE la table (PostgreSQL fait un OU entre policies permissives). Correctif : scoper au GUC app.club_id, ou ajouter une dérogation JUSTIFIÉE dans ce test.',
                $where,
                '' === $qual ? '<none>' : $qual,
                '' === $withCheck ? '<none>' : $withCheck,
                $canon,
            ));
        }

        // D3 : allowlist BIDIRECTIONNELLE — une entrée qui n'a été consommée par
        // aucune policy réellement ouverte est périmée (le durcissement a été fait,
        // ou la table a disparu). Une allowlist qui ne peut pas se périmer ment.
        foreach (array_keys($openSelectAllowlist) as $key) {
            self::assertArrayHasKey(
                $key,
                $consumedAllowlist,
                \sprintf('allowlist périmée : aucune policy SELECT ouverte pour \'%s\' — retirer l\'entrée de $openSelectAllowlist.', $key),
            );
        }
        foreach (array_keys($insertPredicateOverrides) as $key) {
            self::assertArrayHasKey(
                $key,
                $consumedOverrides,
                \sprintf('dérogation périmée : aucune policy INSERT hors-canon pour \'%s\' — retirer l\'entrée de $insertPredicateOverrides.', $key),
            );
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

    /**
     * Une policy est tenant-scopée ssi son/ses prédicat(s) égalent STRICTEMENT le
     * canon, selon la commande :
     *  - ALL/SELECT/UPDATE : qual === canon ET (with_check vide OU === canon)
     *  - DELETE : qual === canon
     *  - INSERT : with_check === canon (qual est NULL pour INSERT).
     */
    private function policyMatchesCanon(string $cmd, string $qual, string $withCheck, string $canon): bool
    {
        return match ($cmd) {
            'ALL', 'SELECT', 'UPDATE' => $qual === $canon && ('' === $withCheck || $withCheck === $canon),
            'DELETE' => $qual === $canon,
            'INSERT' => $withCheck === $canon,
            default => false,
        };
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
